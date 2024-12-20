<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\ShopifyManager;
use App\Service\SlackManager;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\DatabaseManager;

class VariantsOutOfStockManager implements WebhookManagerInterface
{
    private $httpClient;
    private $params;
    private SlackManager $slackManager;

    public function __construct( HttpClientInterface $httpClient , SlackManager $slackManager, ParameterBagInterface $params ) {
        $this->httpClient = $httpClient;
        $this->params = $params;
        $this->slackManager = $slackManager;
    }

    public function handle(DatabaseManager $databaseManager, array $payload, array $config): array
    {
        $channel_id = '';
        $message = '';
        $product_title = 'Example title';
        $variant_title = 'Example variant';

        //get the channel for variant out of stock update
        //get the prefix message
        if( isset( $config['settings'] ) && count( $config['settings'] ) > 0 ) {
            foreach( $config['settings'] as $setting ) {
                if( $setting['id'] == 'outOfStockAlerts' ) {
                    $channel_id = $setting['channel']['value'] ?? null;
                    $message = $setting['message'];
                    break;
                }
            }
        }

        //fetch product details using graphql
        [ $shop, $shop_access_key ] = $databaseManager->getShopifyStoreAccessToken( $databaseManager->getCurrentUser() );
        $shopifyManager = new ShopifyManager($this->params, $this->httpClient, $config['shop'], $shop_access_key);

        //find the detail of variant also
        $variants = $shopifyManager->get_variants( [ $payload['id'] ] );
        $variants = $variants['productVariant1'];
        $product_title = ( isset( $variants['product']['title'] ) ? $variants['product']['title'] : '' );
        $variant_title = ( isset( $variants['title'] ) ? $variants['title'] : '' );
        $variant_link = 'https://admin.shopify.com/store/' . str_replace('.myshopify.com', '', $config['shop']) . '/products/' . str_replace( 'gid://shopify/Product/', '', $variants['product']['id'] ) . '/variants/' . str_replace( 'gid://shopify/ProductVariant/', '', $variants['id'] );
        //generate a summary of order

        $full_message = sprintf(
            "%s\nVariant: %s\nProduct: %s\n%s",
            $message,
            $product_title,
            $variant_title,
            $variant_link
        );
        
        //send message request if channel is set
        $slack_api_url = 'https://slack.com/api/chat.postMessage';
        $access_token = $config['connection']['access_token'] ?? '';
        
        $response = '';
        if( $access_token != '' && $channel_id != '' ) {
            $response = $this->slackManager->send_message( $access_token, $channel_id, $full_message );
        }

        return [ 'response' => $response, 'message' => $full_message ];
    }
}