<?php

namespace App\Service;

interface WebhookManagerInterface
{
    public function handle(DatabaseManager $databaseManager, array $payload, array $config): array;
}