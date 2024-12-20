<?php
// src/Service/ShopifyManager.php
namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SlackManager
{
    private string $apiKey;
    private string $apiClientId;
    private string $apiClientSecret;
    private string $apiSigningSecret;
    private string $apiVerificationToken;
    private HttpClientInterface $httpClient;

    public function __construct(ParameterBagInterface $params, HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        $this->apiKey = $params->get('slack.api_key');
        $this->apiClientId = $params->get('slack.api_client_id');
        $this->apiClientSecret = $params->get('slack.api_client_secret');
        $this->apiSigningSecret = $params->get('slack.api_signing_secret');
        $this->apiVerificationToken = $params->get('slack.api_verififcation_token');
    }

    public function get_connect_url( string $scopes, string $userScopes): string 
    {
        return 'https://slack.com/oauth/v2/authorize?scope=' . urlencode( $scopes ) . '&user_scope=' . urlencode( $userScopes ) . '&client_id=' . $this->apiClientId;
    }

    public function validate_code( string $code, string $redirect_uri ): array
    {
        try {
            $response = $this->httpClient->request('POST', 'https://slack.com/api/oauth.v2.access', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'code'          => $code,
                    'client_id'     => $this->apiClientId,
                    'client_secret' => $this->apiClientSecret,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $redirect_uri
                ],
                // Timeout settings
                'timeout' => 15.0, // Increase timeout to 15 seconds
            ]);

            // Get the HTTP status code
            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $data = $response->toArray(); // Parse the response as JSON
                return $data;
            } else {
                echo 'HTTP Error: ' . $statusCode;
            }
        } catch (TransportExceptionInterface $e) {
            echo 'Request failed: ' . $e->getMessage();
        }
    }

    public function conversations_list( string $access_token ): ?array 
    {
        //Fetch slack channels
        $response = $this->httpClient->request('GET', 'https://slack.com/api/conversations.list', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
        ]);

        $statusCode = $response->getStatusCode(); // 200
        $content = $response->getContent(); // JSON response as string
        $data = $response->toArray(); // Decoded JSON as PHP array

        // Example: Print the channel names
        if ($statusCode === 200) {
            return $data['channels'];
        } else return NULL;
    }

    public function send_message( string $access_token, string $channel_id, string $message ): ?array 
    {
        //First make a join request
        $join = $this->join_channel( $access_token, $channel_id );

        //If joined, notify
        if( $join ) {
            try {
                // Make the HTTP request using the HttpClient
                $response = $this->httpClient->request('POST', 'https://slack.com/api/chat.postMessage', [
                    'headers' => [
                        'Content-Type' => 'application/json; charset=utf-8',
                        'Authorization' => 'Bearer ' . $access_token,
                    ],
                    'json' => [
                        'channel' => $channel_id,
                        'text' => $message,
                    ],
                ]);

                // Get the status code (optional)
                $statusCode = $response->getStatusCode();
                if ($statusCode === 200) {
                    // Process successful response
                    $content = $response->toArray();  // Parse JSON response into an array
                    return $content;
                } else {
                    // Handle non-200 responses
                    //return 'Error: ' . $statusCode;
                }
            } catch (TransportExceptionInterface $e) {
                // Handle any transport exceptions (e.g., connection issues)
                return 'Error: ' . $e->getMessage();
            }
        } else return $join;

        return false;
    }

    public function join_channel( string $access_token, string $channel_id ): ?array 
    {
        try {
            // Make the HTTP request using the HttpClient
            $response = $this->httpClient->request('POST', 'https://slack.com/api/conversations.join', [
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Authorization' => 'Bearer ' . $access_token,
                ],
                'json' => [
                    'channel' => $channel_id
                ],
            ]);

            // Get the status code (optional)
            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                // Process successful response
                $content = $response->toArray();  // Parse JSON response into an array
                return $content;
            }
        } catch (TransportExceptionInterface $e) {
            // Handle any transport exceptions (e.g., connection issues)
            return 'Error: ' . $e->getMessage();
        }

        return false;
    }
}
?>