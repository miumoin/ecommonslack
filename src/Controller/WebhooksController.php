<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\DatabaseManager;
use App\Service\SlackManager;
use App\Service\VariantsOutOfStockManager;
use App\Service\OrdersCreateManager;
use Psr\Log\LoggerInterface;

class WebhooksController extends AbstractController
{
    private $entityManager;
    private $params;
    private $variantsOutOfStockManager;
    private $ordersCreateManager;

    public function __construct(EntityManagerInterface $entityManager, ParameterBagInterface $params, OrdersCreateManager $ordersCreateManager, VariantsOutOfStockManager $variantsOutOfStockManager)
    {
        $this->entityManager = $entityManager;
        $this->params = $params;
        $this->ordersCreateManager = $ordersCreateManager;
        $this->variantsOutOfStockManager = $variantsOutOfStockManager;
    }

	#[Route('/webhooks/listen', name: 'listen_webhooks')]
	function listen( Request $request, LoggerInterface $logger ): Response
	{
	    $shop = 'slack-managed.myshopify.com'; //$request->headers->get('X-Shopify-Shop-Domain');
		$topic = $request->headers->get('X-Shopify-Topic');
		$data = $request->getContent();
	    $app_secret_key = $this->params->get('shopify.api_secret');
	    $hmacHeader = $request->headers->get('X-Shopify-Hmac-SHA256');
	    $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $app_secret_key, true));

	    $domain = 'localhost:8000'; //$request->headers->get('host');
	    $databaseManager = new DatabaseManager( $this->entityManager, $domain, '' );
	    [ $user_id, $access_key, $password ] = $databaseManager->getShopifyStore( $shop );
	    if( $access_key ) $databaseManager = new DatabaseManager( $this->entityManager, $domain, $access_key );

	    $slack_authorization_key = $databaseManager->getMeta('user', $user_id, 'slack_authorization_key');
	    if( $slack_authorization_key ) $slack_authorization_key = json_decode($slack_authorization_key, true);
	    
	    $slack_notification_settings = $databaseManager->getMeta('user', $user_id, 'slack_notification_settings' );
	    if( $slack_notification_settings ) $slack_notification_settings = json_decode( $slack_notification_settings, true );

	    $config = ['connection' => $slack_authorization_key, 'settings' => $slack_notification_settings, 'shop' => $shop ];

	    //file_put_contents('../_temp_/webhooks_entry.txt', $request);
	    //$logger->info('Webhook received', ['payload' => $payload]);
		//$logger->info( 'Shop: ' . $shop . ' - topic: ' . $topic );

	    if (hash_equals($hmacHeader, $calculatedHmac)) {
	        // Log the payload
	        $payload = json_decode($data, true);

	        switch( $topic ) {
	        	case 'orders/create': 
	        		$res = $this->ordersCreateManager->handle( $databaseManager, $payload, $config );
	        		//also add product low cost checker
	        		$logger->info('Webhook received', ['res' => $res]);
	        		break;
	        	case 'variants/out_of_stock':
	        		$res = $this->variantsOutOfStockManager->handle( $databaseManager, $payload, $config );
	        		$logger->info('Webhook received', ['res' => $res]);
	        		break;
	        }
			
			return new Response(
				json_encode(['message' => 'Success!']), // The response body
            	200,                                    // HTTP status code
            	['Content-Type' => 'application/json'] 
			);
		}
	}
}
?>