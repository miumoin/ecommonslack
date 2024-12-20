<?php

namespace App\Service;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\DatabaseManager;
use App\Service\ShopifyManager;
use App\Service\SlackManager;
use App\Service\Utilities;
use DateTime;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;

class Utilities
{
    private $entityManager;
    private $params;
    private $httpClient;

    //no functions yet
    public function __construct(ParameterBagInterface $params, EntityManagerInterface $entityManager, HttpClientInterface $httpClient)
    {
        $this->entityManager = $entityManager;
        $this->httpClient = $httpClient;
        $this->params = $params;
    }

    public function get_notifiable_shops( string $notification_type ): array 
    {
        $now = new DateTime('now', new DateTimeZone('GMT'));
        $yesterday = $now->modify('-24 hours');
        
        $subQb = $this->entityManager->createQueryBuilder();
        $subQuery = $subQb->select('m.parent_id')
            ->from('App\Entity\Metas', 'm')
            ->where('m.parent = :parent')
            ->andWhere('m.meta_key = :metaKey')
            ->andWhere('m.meta_value > :metaValue')
            ->setParameter('parent', 'user')
            ->setParameter('metaKey', $notification_type . '_notified')
            ->setParameter('metaValue', $yesterday->format('Y-m-d H:i:s'));

        $qb = $this->entityManager->createQueryBuilder();
        $query = $qb->select('u')
            ->from('App\Entity\Users', 'u')
            ->where($qb->expr()->notIn('u.id', $subQuery->getDQL()))
            ->setParameters($subQuery->getParameters())
            ->getQuery();

        $results = $query->getResult();

        //Now filter out the shops who are in a timezone where time is between 10AM to 12AM
        $ids = [];
        foreach( $results as $res ) {
            $ids[] = $res->getId();
        } 

        if( count( $ids ) > 0 ) {
            $tq = $this->entityManager->createQueryBuilder();
            $tquery = $tq->select('m.parent_id, m.meta_value')
                ->from('App\Entity\Metas', 'm')
                ->where('m.parent = :parent')
                ->andWhere('m.meta_key = :metaKey')
                ->andWhere($tq->expr()->in('m.parent_id', ':parentIds'))
                ->setParameter('parent', 'user')
                ->setParameter('metaKey', 'timezoneOffset')
                ->setParameter('parentIds', $ids )
                ->getQuery();
            $timezones = $tquery->getResult();
            $tzs = [];
            foreach( $timezones as $tz ) {
                $tzs[ $tz['parent_id'] ] = $tz['meta_value'];
            }
        }

        $updated_results = [];

        foreach( $results as $result ) {
            // Parse the offset (e.g., "-0500" or "-0230")
            $hours = (int) substr($tzs[ $result->getId() ], 0, 3); // First 3 characters for hours
            $minutes = (int) substr($tzs[ $result->getId() ], 0, 1) . substr($tzs[ $result->getId() ], -2); // Sign + last 2 digits for minutes
            $offsetInSeconds = ($hours * 3600) + ($minutes * 60);

            $utcTime = new DateTime('now', new DateTimeZone('UTC'));
            $targetTime = clone $utcTime;
            $targetTime->modify( "{$offsetInSeconds} seconds");
            $currentHour = (int) $targetTime->format('H');

            if( $currentHour >= 0 && $currentHour <= 24 ) {
            //if( $currentHour >= 10 && $currentHour <= 12 ) {
                $updated_results[] = $result;
            }
        }

        return $updated_results ?? [];
    }

    public function is_receiving_notification( int $shop_id, string $notification_type ): ?array 
    {
        $databaseManager = new DatabaseManager($this->entityManager, '', '');
        $settings = $databaseManager->getMeta('user', $shop_id, 'slack_notification_settings');

        if( $settings ) {
            $settings = json_decode( $settings, true );
            if( count( $settings ) > 0 ) {
                foreach( $settings as $setting ) {
                    if( $setting['id'] == $notification_type ) {
                        if( isset( $setting['channel'] ) && $setting['channel']['value'] != '' ) {
                            return $setting;
                        } else {
                            $databaseManager->addMeta( 'user', $shop_id, $notification_type . '_notified', date("Y-m-d 10:00:00") );
                        }
                        break;
                    }
                }
            }
        }

        return null;
    }

    public function get_orders_summary( int $shop_id, string $shop, string $access_key, int $number_of_days ): array
    {
        $now = new DateTime('now', new DateTimeZone('GMT'));
        $otherday = clone $now;
        $start_date = $otherday->modify('-' . $number_of_days . ' days');
        $after = '';
        $orders = [];
        $numOrders = 250;
        $ShopifyManager = new ShopifyManager($this->params, $this->httpClient, $shop, $access_key);

        $refund_count = 0;
        $fulfilled_count = 0;
        $new_order_count = 0;
        $total_revenue = 0;

        $i = 0;
        $continues = true;
        while( $continues != false ) {
            $query = 'query($numOrders: Int!' . ( $after != '' ? ', $cursor: String' : '' ) . ') {
    orders(first: $numOrders, ' . ( $after != '' ? 'after: $cursor,' : '' ) . ' query: "created_at:>=' . $start_date->format('Y-m-d') . ' created_at:<' . $now->format('Y-m-d') . '", reverse: false) {
        edges {
            node {
                id
                name
                createdAt
                updatedAt
                displayFulfillmentStatus
                displayFinancialStatus
                totalPrice
            }
            cursor
        }
    }
}';         

            $variables = array( 'numOrders' => $numOrders );
            if( $after != '' ) $variables['cursor'] = $after;

            $query = json_encode( array( 'query' => $query, 'variables' => $variables ) );
            $result = $ShopifyManager->graph_query( '2024-10', $query );


            if( isset( $result['data'] ) && isset( $result['data']['orders'] ) && count( $result['data']['orders']['edges'] ) > 0 ) {
                foreach( $result['data']['orders']['edges'] as $edge ) {
                    $orders[] = $edge;

                    if( $edge['node']['displayFulfillmentStatus'] == 'FULFILLED' ) $fulfilled_count++;
                    if( $edge['node']['displayFinancialStatus'] == 'REFUNDED' ) {
                        $refund_count++;
                        $total_revenue = ( $total_revenue - $edge['node']['totalPrice'] );
                    }
                    if( strtotime( $edge['node']['createdAt'] ) >= strtotime( $start_date->format('Y-m-d') ) &&  strtotime( $edge['node']['createdAt'] ) <= strtotime( $now->format('Y-m-d') ) ) {
                        $new_order_count++;
                        $total_revenue = ( $total_revenue + $edge['node']['totalPrice'] );
                    }

                    $after = $edge['cursor'];
                }
                
                if( count( $result['data']['orders']['edges'] ) < $numOrders ) $continues = false;
            } else $continues = false;
        }

        $databaseManager = new DatabaseManager($this->entityManager, '', '');
        $money_format = $databaseManager->getMeta('user', $shop_id, 'moneyFormat');
        $total_revenue = str_replace( ['{{amount}}', '{{amount_no_decimals}}', '{{amount_with_comma_separator}}', '{{amount_no_decimals_with_comma_separator}}', '{{amount_with_apostrophe_separator}}', '{{amount_no_decimals_with_space_separator}}', '{{amount_with_space_separator}}', '{{amount_with_period_and_space_separator}}'], $total_revenue, $money_format);

        $orders_info = [
            'New orders: ' . $new_order_count,
            'Completed orders: ' . $fulfilled_count,
            'Cancelled orders: ' . $refund_count,
            'Total revenue: ' . $total_revenue
        ];

        return $orders_info;
    }

    public function get_low_stock( int $shop_id, string $shop, string $access_key, int $number_of_days ): array
    {
        $now = new DateTime('now', new DateTimeZone('GMT'));
        $start_date = $now->modify('-' . $number_of_days . ' days');
        $after = '';
        $low_stock_products = [];
        $products = [];
        $numOrders = 250;

        $i = 0;
        $continues = true;
        while( $continues != false ) {
            $query = 'query($numOrders: Int!' . ( $after != '' ? ', $cursor: String' : '' ) . ') {
    orders(first: $numOrders, ' . ( $after != '' ? 'after: $cursor,' : '' ) . ' query: "created_at:>=' . $start_date->format('c') . '", reverse: false) {
        edges {
            node {
                id
                name
                createdAt
                totalPrice
                lineItems(first: 250) {
                    edges {
                        node {
                            id
                            product {
                                id
                            }
                            variant {
                                id
                            }
                            quantity
                        }
                    }
                }
            }
            cursor
        }
    }
}';         
            $variables = array( 'numOrders' => $numOrders );
            if( $after != '' ) $variables['cursor'] = $after;

            $query = json_encode( array( 'query' => $query, 'variables' => $variables ) );

            $ShopifyManager = new ShopifyManager($this->params, $this->httpClient, $shop, $access_key);
            $result = $ShopifyManager->graph_query( '2024-10', $query );

            if( isset( $result['data'] ) && isset( $result['data']['orders'] ) && count( $result['data']['orders']['edges'] ) > 0 ) {
                foreach( $result['data']['orders']['edges'] as $edge ) {
                    if( isset( $edge['node']['lineItems'] ) && isset( $edge['node']['lineItems']['edges'] ) && count( $edge['node']['lineItems']['edges'] ) > 0 ) {
                        foreach( $edge['node']['lineItems']['edges'] as $lineitem ) {
                            $product_id = str_replace( 'gid://shopify/Product/', '', $lineitem['node']['product']['id'] );
                            $variant_id = str_replace( 'gid://shopify/ProductVariant/', '', $lineitem['node']['variant']['id'] );
                            $quantity = $lineitem['node']['quantity'];

                            if( isset( $products[ $product_id ] ) && isset( $products[ $product_id ][ $variant_id ] ) ) $products[ $product_id ][ $variant_id ] += $quantity;
                            else $products[ $product_id ][ $variant_id ] = $quantity;
                        }
                    }
                    $after = $edge['cursor'];
                }
                
                if( count( $result['data']['orders']['edges'] ) < $numOrders ) $continues = false;
            } else $continues = false;
        }

        $query = 'query($variantIds: [ID!]!) {
    nodes(ids: $variantIds) {
        ... on ProductVariant {
            id
            title
            inventoryQuantity
            product {
                id
                title
                hasOnlyDefaultVariant
            }
        }
    }
}';
    
        $variant_ids = [];
        if( count( $products ) > 0 ) {
            foreach( array_keys( $products ) as $product_id ) {
                foreach( array_keys( $products[ $product_id ] ) as $variant_id ) {
                    $variant_ids[] = 'gid://shopify/ProductVariant/' . $variant_id;
                }
            } 
        }

        $query = json_encode( [ 'query' => $query, 'variables' => [ 'variantIds' => $variant_ids ] ] );
        $result = $ShopifyManager->graph_query( '2024-10', $query );

        $databaseManager = new DatabaseManager($this->entityManager, '', '');
        $low_stock_notified = $databaseManager->getMeta('user', $shop_id, 'low_stock_notified');
        if( !$low_stock_notified ) {
            $low_stock_notified = [];
        } else {
            $low_stock_notified = json_decode( $low_stock_notified, true );
        }

        if( isset( $result['data'] ) && isset( $result['data']['nodes'] ) && count( $result['data']['nodes'] ) > 0 ) {
            foreach( $result['data']['nodes'] as $variant ) {
                $variant_id = str_replace( 'gid://shopify/ProductVariant/', '', $variant['id'] );
                $product_id = str_replace( 'gid://shopify/Product/', '', $variant['product']['id'] );
                $inventory_quantity = $variant['inventoryQuantity'];

                if( $inventory_quantity < $products[ $product_id ][ $variant_id ] ) {
                    if( !isset( $low_stock_notified[ $product_id ] ) || !isset( $low_stock_notified[ $product_id ][ $variant_id ] ) || ( strtotime( $low_stock_notified[ $product_id ][ $variant_id ] ) < strtotime( $start_date->format('Y-m-d') ) ) ) {
                        $low_stock_products[] = $variant['product']['title'] . ( $variant['product']['hasOnlyDefaultVariant'] ? '' : ' - ' . $variant['title'] ) . ' (https://admin.shopify.com/store/' . str_replace('.myshopify.com', '', $shop) . '/products/' . $product_id . '/variants/' . $variant_id . ')';

                        if( isset( $low_stock_notified[ $product_id ] ) && ( isset( $low_stock_notified[ $product_id ][ $variant_id ] ) ) ) {
                            $low_stock_notified[ $product_id ][ $variant_id ] = date('Y-m-d');
                        } else {
                            $low_stock_notified[ $product_id ] = [ $variant_id => date('Y-m-d') ];
                        }
                    }
                }
            }
        }

        $databaseManager->addMeta('user', $shop_id, 'low_stock_notified', $low_stock_notified);

        return $low_stock_products;
    }

    public function notify_on_slack( int $id, array $info, array $settings, string $type ): bool 
    {
        $databaseManager = new DatabaseManager( $this->entityManager, '', '' );
        $slack_authorization_key = $databaseManager->getMeta('user', $id, 'slack_authorization_key');
        if( $slack_authorization_key ) {
            $slack_authorization_key = json_decode($slack_authorization_key, true);

            if( count( $info ) > 0 ) {
                $message_prefix = ( isset( $settings['message'] ) && trim( $settings['message'] ) != '' ? $settings['message'] . PHP_EOL : '' );
                $message = '';
                if( count( $info ) > 0 ) {
                    for( $i = 0; $i < count( $info ); $i++ ) {
                        if( $i > 0 ) $message .= PHP_EOL;
                        $message .= $info[ $i ];
                    }
                }

                $full_message = $message_prefix . $message;

                $channel_id = $settings['channel']['value'];
                $slack_api_url = 'https://slack.com/api/chat.postMessage';
                $access_token = $slack_authorization_key['access_token'] ?? '';

                $slackManager = new SlackManager( $this->params, $this->httpClient );

                $response = '';
                if( $access_token != '' && $channel_id != '' ) {
                    $response = $slackManager->send_message( $access_token, $channel_id, $full_message );
                    
                }
            }

            $databaseManager->addMeta( 'user', $id, $type . '_notified', date("Y-m-d 10:00:00") );

            return true;
        }
    }
}