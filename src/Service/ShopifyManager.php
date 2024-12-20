<?php
// src/Service/ShopifyManager.php
namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ShopifyManager
{
    private string $apiKey;
    private string $apiSecret;
    private string $apiScopes;
    private string $shop;
    private string $shopAccessKey;
    private HttpClientInterface $httpClient;

    public function __construct(ParameterBagInterface $params, HttpClientInterface $httpClient, string $shop, string $shopAccessKey)
    {
        $this->httpClient = $httpClient;
        $this->apiKey = $params->get('shopify.api_key');
        $this->apiSecret = $params->get('shopify.api_secret');
        $this->apiScopes = $params->get('shopify.api_scopes');
        $this->shop = $shop;
        $this->shopAccessKey = $shopAccessKey;
    }

    public function getShopifyCredentials(): array
    {
        return [
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
        ];
    }

    public function get_installation_url(): string 
    {
        return 'https://' . $this->shop . '/admin/oauth/authorize?client_id=' . $this->apiKey . '&scope=' . $this->apiScopes;
    }

    public function validate_code( $code, $timestamp, $host, $hmac ): bool
    {
        // Step 1: Validate the HMAC to ensure the request is from Shopify
        $params = array( 'shop' => $this->shop, 'code' => $code, 'host' => $host, 'timestamp' => $timestamp );
        ksort($params);
        $computedHmac = hash_hmac('sha256', http_build_query($params), $this->apiSecret);
        if (!hash_equals($hmac, $computedHmac)) {
            return false;
        } else {
            return true;
        }
    }

    public function get_access_token( $code ): string
    {
        $url = 'https://' . $this->shop . '/admin/oauth/access_token';

        try {
            // Making the POST request
            $response = $this->httpClient->request('POST', $url, [ 'json' => [ 'client_id' => $this->apiKey, 'client_secret' => $this->apiSecret, 'code' => $code ] ] );

            // Check the response status
            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Failed to fetch access token: ' . $response->getContent(false));
            }

            // Return the JSON-decoded response as an array
            return $response->toArray()['access_token'];
        } catch (\Exception $e) {
            // Log or handle the error appropriately
            throw new \RuntimeException('Error while requesting access token: ' . $e->getMessage());
        }
    }

    public function graph_query( string $version, string $query ): array
    {
        $url = sprintf('https://%s/admin/api/%s/graphql.json', $this->shop, $version);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Shopify-Access-Token' => $this->shopAccessKey,
                ],
                'body' => $query,
            ]);
            
            $result = $response->toArray();
            return $result;
        } catch (TransportExceptionInterface $e) {
            // Handle transport exceptions
            throw new \RuntimeException('HTTP request failed: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            // Handle other exceptions
            throw new \RuntimeException('An error occurred: ' . $e->getMessage(), 0, $e);
        }
    }

    public function register_webhooks( array $webhooks, string $endpoint ): bool
    {
        //Get previously added webhooks
        $query = 'query {
  webhookSubscriptions(first: 50) {
    edges {
      node {
        id
        topic
        endpoint {
          __typename
          ... on WebhookHttpEndpoint {
            callbackUrl
          }
          ... on WebhookEventBridgeEndpoint {
            arn
          }
          ... on WebhookPubSubEndpoint {
            pubSubProject
            pubSubTopic
          }
        }
      }
    }
  }
}';
        $query = json_encode( array( 'query' => $query ) );
        $result = $this->graph_query( '2024-10', $query );

        $prevWebhooks = $result['data']['webhookSubscriptions']['edges'] ?? [];

        //Delete them
        if( count($prevWebhooks ) > 0 ) {
            foreach( $prevWebhooks as $webhook ) {
                //Deregister it
                $query = 'mutation webhookSubscriptionDelete($id: ID!) {
  webhookSubscriptionDelete(id: $id) {
    userErrors {
      field
      message
    }
    deletedWebhookSubscriptionId
  }
}';
                $variables = array( 'id' => $webhook['node']['id'] );
                $query = json_encode( array( 'query' => $query, 'variables' => $variables ) );
                $result = $this->graph_query( '2024-10', $query );
            }
        }

        //Now register given webhooks
        foreach( $webhooks as $webhook ) {
            //Register webhooks
            $query = 'mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
  webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
    webhookSubscription {
      id
      topic
      filter
      format
      endpoint {
        __typename
        ... on WebhookHttpEndpoint {
          callbackUrl
        }
      }
    }
    userErrors {
      field
      message
    }
  }
}';
            $variables = array(
                "topic" => $webhook,
                "webhookSubscription" => array(
                    "callbackUrl" => $endpoint,
                    "format" => "JSON"
                )
            );

            $query = json_encode( array( 'query' => $query, 'variables' => $variables ) );
            $result = $this->graph_query( '2024-10', $query );
        }

        return true;
    }

    public function get_variants( array $variant_ids ): array 
    {
        $query = '';
        if( count( $variant_ids ) > 0 ) {
            for( $i = 0; $i < count( $variant_ids ); $i++ ) {
                $query .= 'productVariant' . ( $i + 1 ) . ': productVariant(id: "gid://shopify/ProductVariant/' . $variant_ids[$i] . '") {
        id
        title
        product {
            id 
            title
        }
    }';
            }
        }

        $query = 'query {
    ' . $query . '

}';

        $query = json_encode( array( 'query' => $query ) );
        $result = $this->graph_query( '2024-10', $query );

        return $result['data'];
    }

    public function get_shop( array $fields ): array 
    {
        $query = '';
        for( $i = 0; $i < count( $fields ); $i++ ) {
            $query .= '
        ' . $fields[$i];
        }
        $query = 'query {
    shop {' . $query . '
    }
}';
        $query = json_encode( array( 'query' => $query ) );
        $result = $this->graph_query( '2024-10', $query );
        return $result['data']['shop'];
    }

    public function fetch_low_stocks( array $variants ): array
    {
        /*
            Step 1: fetch orders of last 10 days
            Step 2: make a list of variants ordered by the frequency of sales
            Step 3: make find inventory of the products sold last 10 days
            Step 4: stock requires if the number of stock is less than number of items sold
            Step 5: keep track of notified variants
            Step 6: don't notify about the same variant more than once in every 10 days
        */
    }

    public function fetch_daily_summary(): array 
    {
        /*
            Step 1: fetch all orders of today
            Step 2: make a list of orders, status and list of refund
            Step 3: make a list of variants that don't have any stock
            Step 4: make a list of variants that are low in stock
            Step 5: don't notify twice for a day, notify at 10 am for the last day
        */
    }
}
?>