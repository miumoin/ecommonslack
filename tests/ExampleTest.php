<?php

use PHPUnit\Framework\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;

class DatabaseManagerTest extends TestCase
{
    private $entityManager;
    private $repository;
    private $DatabaseManager;

    protected function setUp(): void
    {
        // Mock the EntityManager
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        // Mock the Repository
        $this->repository = $this->createMock(ObjectRepository::class);

        // Configure the EntityManager to return the repository
        $this->entityManager
            ->expects($this->any())
            ->method('getRepository')
            ->with(Users::class)
            ->willReturn($this->repository);

        // Instantiate your class with the mocked EntityManager
        $this->DatabaseManager = new DatabaseManager($this->entityManager);
    }

    public function testGetShopifyStoreAccessTokenWithValidUserId()
    {
        $mockUser = $this->createMock(Users::class);
        $mockUser->method('getPassword')->willReturn('mockedPassword');

        // Configure the repository to return the mocked user for a specific user ID
        $this->repository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['id' => 1])
            ->willReturn($mockUser);

        // Test the method
        $result = $this->yourClass->getShopifyStoreAccessToken(1);
        $this->assertEquals('mockedPassword', $result);
    }

    public function testGetShopifyStoreAccessTokenWithInvalidUserId()
    {
        // Configure the repository to return null for an invalid user ID
        $this->repository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['id' => 999])
            ->willReturn(null);

        // Test the method
        $result = $this->yourClass->getShopifyStoreAccessToken(999);
        $this->assertNull($result);
    }
}