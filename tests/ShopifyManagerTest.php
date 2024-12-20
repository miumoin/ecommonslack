<?php

namespace App\Tests\Service;

use App\Service\ShopifyManager;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use PHPUnit\Framework\TestCase;

class ShopifyServiceTest extends TestCase
{
    public function testGetInstallationUrl()
    {
        // Mock the ParameterBagInterface
        $params = $this->createMock(ParameterBagInterface::class);

        // Configure the mocked ParameterBagInterface to return specific values
        $params->method('get')
            ->willReturnMap([
                ['shopify.api_key', 'test-api-key'],
                ['shopify.api_secret', 'test-api-secret'],
                ['shopify.api_scopes', 'read_products,write_orders'],
            ]);

        // Mock input values
        $shop = 'test-shop.myshopify.com';
        $shopAccessKey = 'test-access-key';

        // Create an instance of the service with the mocked ParameterBagInterface
        $service = new ShopifyManager($params, $shop, $shopAccessKey);

        // Expected installation URL
        $expectedUrl = 'https://test-shop.myshopify.com/admin/oauth/authorize?client_id=test-api-key&scope=read_products,write_orders';

        // Assert the result matches the expected URL
        $this->assertEquals($expectedUrl, $service->get_installation_url());
    }
}