<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\DatabaseManager;
use App\Service\ShopifyManager;
use App\Service\SlackManager;
use App\Service\Utilities;

class ApiController extends AbstractController
{
    private $utilities;
    private $entityManager;
    private $databaseManager;
    private $params;
    private $shopifyManager;
    private $slackManager;
    private $httpClient;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $params, HttpClientInterface $httpClient)
    {
        $this->entityManager = $entityManager;
        $this->params = $params;
        $this->httpClient = $httpClient;
        $this->slackManager =  new SlackManager( $params, $httpClient );
        $this->utilities = new Utilities( $params, $entityManager, $httpClient );
    }

    #[Route('/api/get_installation_url', name: 'get_installation_url')]
    public function shopify_get_installation_url(Request $request): JsonResponse
    {
        $content = json_decode($request->getContent(), true);
        $shop = $content['shop'] ?? null;
        if (!$shop) {
            return new JsonResponse(['status' => 'error', 'message' => 'Shop is required'], 400);
        }

        $this->shopifyManager = new ShopifyManager($this->params, $this->httpClient, $shop, '');
        $installation_url = $this->shopifyManager->get_installation_url();
        
        if (!$installation_url) {
            return new JsonResponse(['status' => 'error', 'message' => 'Failed to generate installation URL'], 500);
        }

        // Success response
        return new JsonResponse([
            'status' => 'success',
            'installation_url' => $installation_url,
        ]);
    }

    #[Route('/api/get_access_token', name: 'get_access_token')]
    public function shopify_getAccessToken(Request $request): JsonResponse
    {
        $content = json_decode($request->getContent(), true);
        $shop = $content['shop'] ?? null;
        if (!$shop) {
            return new JsonResponse(['status' => 'error', 'message' => 'Shop is required'], 400);
        }

        $code = $content['code'] ?? null;
        if (!$code) {
            return new JsonResponse(['status' => 'error', 'message' => 'Code is required'], 400);
        }

        $hmac = $content['hmac'];
        if (!$hmac) {
            return new JsonResponse(['status' => 'error', 'message' => 'HMAC is required'], 400);
        }

        $timestamp = $content['timestamp'];
        if (!$timestamp) {
            return new JsonResponse(['status' => 'error', 'message' => 'Timestamp is required'], 400);
        }

        $host = $content['host'];
        if (!$host) {
            return new JsonResponse(['status' => 'error', 'message' => 'Host is required'], 400);
        }

        $this->shopifyManager = new ShopifyManager($this->params, $this->httpClient, $shop, '');
        if( !$this->shopifyManager->validate_code( $code, $timestamp, $host, $hmac ) ) {
            return new JsonResponse(['status' => 'error', 'message' => 'HMAC validation failed'], 400);
        }

        //Save access token
        $access_token = $this->shopifyManager->get_access_token( $code );
        $domain = $request->headers->get('host');
        $databaseManager = new DatabaseManager($this->entityManager, $domain, '');
        $user = $databaseManager->addShopifyStoreAsUser( $shop, $access_token );

        //Save shop timezone
        $shopifyManager = new ShopifyManager($this->params, $this->httpClient, $shop, $access_token);
        $shop_info = $shopifyManager->get_shop( ['timezoneOffset', 'currencyFormats{moneyFormat}'] );
        $databaseManager->addMeta( 'user', $user['id'], 'timezoneOffset', $shop_info['timezoneOffset'] );
        $databaseManager->addMeta( 'user', $user['id'], 'moneyFormat', $shop_info['currencyFormats']['moneyFormat'] );

        // Return the data as a JSON response
        return new JsonResponse(
            array(
                'status'        => 'success',
                'shop'          => $shop,
                'access_key'  => $user['access_key']   
            )
        );
    }

    #[Route('/api/init_slack_connect', name: 'init_slack')]
    public function init_slack_connect( ParameterBagInterface $params ): JsonResponse
    {
        $slack_connect_url = $this->slackManager->get_connect_url( 'channels:read,groups:read,im:read,mpim:read,chat:write,channels:join', 'chat:write,im:history,im:write,channels:read,groups:read,im:read,mpim:read' );
        
        return new JsonResponse(
            array(
                'status'    => 'success',
                'url'       => $slack_connect_url
            )
        );
    }

    #[Route('/api/slack_validate_code', name: 'completion_slack')]
    public function slack_validate_code( ParameterBagInterface $params, Request $request ): JsonResponse
    {
        $content = json_decode($request->getContent(), true);
        $domain = $request->headers->get('host');
        $access_key = $request->headers->get('X-Vuedoo-Access-Key');

        $code = $content['code'] ?? null;
        $redirect_uri = $content['redirect_uri'] ?? null;
        $data = $this->slackManager->validate_code( $code, $redirect_uri );

        if( $data['ok'] ) {
            $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
            $user_id = $databaseManager->getCurrentUser();
            [ $shop, $access_token ] = $databaseManager->getShopifyStoreAccessToken( $user_id );
            $webhook_endpoint = $params->get('shopify.webhook_endpoint');
            $databaseManager->addMeta('user', $user_id, 'slack_authorization_key', $data);
            $databaseManager->addMeta('user', $user_id, 'slack_notification_settings', '[]');

            //Register webhooks if not registered yet
            $this->shopifyManager = new ShopifyManager($this->params, $this->httpClient, $shop, $access_token);
            $this->shopifyManager->register_webhooks(['ORDERS_CREATE', 'VARIANTS_OUT_OF_STOCK'], $webhook_endpoint);

            return new JsonResponse(
                array(
                    'status'    => 'success',
                    'slack_name'=> $data['team']['name']
                )
            );
        } else {
            return new JsonResponse(
                array(
                    'status'    => 'fail'
                )
            );
        }
    }

    #[Route('/api/get_config', name: 'get_config')]
    public function get_config( ParameterBagInterface $params, Request $request ): JsonResponse
    {
        $content = json_decode($request->getContent(), true);
        $domain = $request->headers->get('host');
        $access_key = $request->headers->get('X-Vuedoo-Access-Key');
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $user_id = $databaseManager->getCurrentUser();
        $slack_authorization_key = $databaseManager->getMeta('user', $user_id, 'slack_authorization_key');
        $slack_notification_settings = $databaseManager->getMeta('user', $user_id, 'slack_notification_settings');
        $slack_name = '';
        $slack_channels = [];
        if( $slack_authorization_key != null ) {
            $slack_authorization_key = json_decode( $slack_authorization_key, true );
            $slack_name = ( isset( $slack_authorization_key['team']['name'] ) ? $slack_authorization_key['team']['name'] : '' );

            $slack_notification_settings = ( $slack_notification_settings != null ? json_decode( $slack_notification_settings, true ) : array() );

            $slack_channels = $this->slackManager->conversations_list( $slack_authorization_key['access_token'] );
        }

        if( $slack_name != '' ) {
            return new JsonResponse(
                array(
                    'status'        => 'success',
                    'slack_name'    => $slack_name,
                    'slack_channels'=> $slack_channels,
                    'notifications' => $slack_notification_settings
                )
            );
        } else {
            return new JsonResponse(
                array(
                    'status'    => 'fail'
                )
            );
        }
    }

    #[Route('/api/save_config', name: 'save_config')]
    public function save_config( ParameterBagInterface $params, Request $request ): JsonResponse
    {
        $content = json_decode($request->getContent(), true);
        $domain = $request->headers->get('host');
        $access_key = $request->headers->get('X-Vuedoo-Access-Key');
        $databaseManager = new DatabaseManager($this->entityManager, $domain, $access_key);
        $user_id = $databaseManager->getCurrentUser();

        if( $user_id > 0 ) {
            /*
            ** Compare previous settings with new one
            ** Register or de-register webhook if required
            */
            $slack_notification_settings = $databaseManager->getMeta('user', $user_id, 'slack_notification_settings' );
            if( $slack_notification_settings != null ) $slack_notification_settings = json_decode( $slack_notification_settings, true );

            $slack_notifications = $content['slack_notifications'];

            /*
            ** Save new settings
            */
            $databaseManager->addMeta('user', $user_id, 'slack_notification_settings', $content['slack_notifications'] );
            
            return new JsonResponse(
                array(
                    'status'        => 'success'
                )
            );
        } else {
            return new JsonResponse(
                array(
                    'status'    => 'fail'
                )
            );
        }
    }

    #[Route('/api/test_something', name: 'test_something')]
    public function test_something(): JsonResponse {
        /*$shopifyManager = new ShopifyManager($this->params, $this->httpClient, 'slack-managed.myshopify.com', 'shpua_2f3a1e510d01fd60bf995e6ce099b2e1');
        $shop = $shopifyManager->get_shop( ['timezoneOffset', 'taxShipping'] );
        echo '<pre>';
        var_dump( $shop['timezoneOffset'] );
        */

        //Find a list of shops that didn't get a low stock notification within last 24 hours
        $notifiable_shops = $this->utilities->get_notifiable_shops('lowStockAlerts');
        if( count( $notifiable_shops ) > 0 ) {
            $id = $notifiable_shops[0]->getId();
            $shop = $notifiable_shops[0]->getEmail();
            $access_key = $notifiable_shops[0]->getPassword();

            $is_receiving_low_stock = $this->utilities->is_receiving_notification( $id, 'lowStockAlerts' );
            if( $is_receiving_low_stock ) {
                $low_stock_products = $this->utilities->get_low_stock( $id, $shop, $access_key, 7);
                $this->utilities->notify_low_stock( $id, $low_stock_products, $is_receiving_low_stock );
            }
            //Put a datetime on database so that this shop doesn't get notification again in 24 hour

        }

        //Filter the shops whose day has passed 10AM in their zone
        die();
        return new JsonResponse(
            array(
                'status'    => 'success'
            )
        );
    }

    #[Route('/api/cron_operation_low_stock_alerts', name: 'low_stock_alerts')]
    public function cron_operation_low_stock_alerts(): JsonResponse {
        //Find a list of shops that didn't get a low stock notification within last 24 hours
        $notifiable_shops = $this->utilities->get_notifiable_shops('lowStockAlerts');
        if( count( $notifiable_shops ) > 0 ) {
            $id = $notifiable_shops[0]->getId();
            $shop = $notifiable_shops[0]->getEmail();
            $access_key = $notifiable_shops[0]->getPassword();

            $is_receiving_low_stock = $this->utilities->is_receiving_notification( $id, 'lowStockAlerts' );
            if( $is_receiving_low_stock ) {
                $low_stock_products = $this->utilities->get_low_stock( $id, $shop, $access_key, 7);
                $this->utilities->notify_on_slack( $id, $low_stock_products, $is_receiving_low_stock, 'lowStockAlerts' );
            }
        }

        return new JsonResponse(
            array(
                'status'    => 'success'
            )
        );
    }

    #[Route('/api/cron_operation_summary_alerts', name: 'summary_alerts')]
    public function cron_operation_summary_alerts(): JsonResponse {
        //Find a list of shops that didn't get a low stock notification within last 24 hours
        $notifiable_shops = $this->utilities->get_notifiable_shops('dailySummary');
        if( count( $notifiable_shops ) > 0 ) {
            $id = $notifiable_shops[0]->getId();
            $shop = $notifiable_shops[0]->getEmail();
            $access_key = $notifiable_shops[0]->getPassword();

            $is_receiving_order_summary = $this->utilities->is_receiving_notification( $id, 'dailySummary' );
            if( $is_receiving_order_summary ) {
                $orders_info = $this->utilities->get_orders_summary( $id, $shop, $access_key, 7 );
                $this->utilities->notify_on_slack( $id, $orders_info, $is_receiving_order_summary, 'dailySummary' );
            }
        }

        //Filter the shops whose day has passed 10AM in their zone
        die();
        return new JsonResponse(
            array(
                'status'    => 'success'
            )
        );
    }
    
}