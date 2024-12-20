<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\ShopifyManager;
use App\Service\SlackManager;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\DatabaseManager;

class OrdersCreateManager implements WebhookManagerInterface
{
    private HttpClientInterface $httpClient;
    private SlackManager $slackManager;

    public function __construct( HttpClientInterface $httpClient , SlackManager $slackManager ) {
        $this->httpClient = $httpClient;
        $this->slackManager = $slackManager;
    }

    public function handle(DatabaseManager $databaseManager, array $payload, array $config): array
    {
        $channel_id = '';
        $message = '';
        $order_summary = '';

        //get the channel for order update
        //get the prefix message
        if( isset( $config['settings'] ) && count( $config['settings'] ) > 0 ) {
            foreach( $config['settings'] as $setting ) {
                if( $setting['id'] == 'orderUpdates' ) {
                    $channel_id = $setting['channel']['value'] ?? null;
                    $message = $setting['message'];
                    break;
                }
            }
        }

        //generate a summary of order
        $customer_name = ( $payload['shipping_address']['name'] ?? '' );

        $shipping_address = $payload['shipping_address']['address1'] ?? '';
        $shipping_address .= ( $shipping_address != '' && $payload['shipping_address']['address2'] != '' ? ', ' . $payload['shipping_address']['address2'] : '' );

        $shipping_address .= ( $payload['shipping_address']['zip'] != '' ? ', ' . $payload['shipping_address']['zip'] : '' ) . ( $payload['shipping_address']['city'] != '' ? ' ' . $payload['shipping_address']['city'] : '' );
        $shipping_address .=  ( $payload['shipping_address']['province'] != '' ? ', ' . $payload['shipping_address']['province'] : '' ) . ( $payload['shipping_address']['country'] != '' ? ', ' . $payload['shipping_address']['country'] : '' );

        $phone_number = $payload['shipping_address']['phone'] ?? '';
        $total_value = $payload['current_total_price'] . ' ' . $payload['currency'];
        $order_link = 'https://admin.shopify.com/store/' . str_replace('.myshopify.com', '', $config['shop']) . '/orders/' . $payload['id'];
        $products = array_map( fn( $product ) => ( $product['name'] . ( ( $product['variant_title'] ?? '' ) !== '' ? ' (' . ( $product['variant_title'] ?? '' ) . ')' : '' ) ),  $payload['line_items'] );
        $product_list = implode( ', ', $products );

        $full_message = sprintf(
            "%s\nCustomer: %s\nAddress: %s\nProducts: %s\nTotal: $%.2f\nView Order: %s",
            $message,
            $customer_name,
            $shipping_address,
            $product_list,
            $total_value,
            $order_link
        );
        //send message request if channel is set
        $slack_api_url = 'https://slack.com/api/chat.postMessage';
        $access_token = $config['connection']['access_token'] ?? '';

        $response = '';
        if( $access_token != '' && $channel_id != '' ) {
            $response = $this->slackManager->send_message( $access_token, $channel_id, $full_message );
        }

        return [ 'response' => $response ];
    }
}