<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Sendly\ApiKey;
use Sendly\Sendly;
use Sendly\Resources\Account;
use Sendly\Exceptions\ValidationException;
use ReflectionClass;

/**
 * Tests for the Account resource's API key management surface.
 */
class AccountTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    /**
     * @param array<int, Response> $responses
     */
    private function createMockClient(array $responses): Sendly
    {
        $this->history = [];
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($this->history));
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new Sendly('test_api_key');

        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($client, $httpClient);

        return $client;
    }

    public function testAccountResourceIsRegistered(): void
    {
        $client = new Sendly('test_api_key');
        $this->assertInstanceOf(Account::class, $client->account);
        $this->assertInstanceOf(Account::class, $client->account());
        $this->assertSame($client->account, $client->account());
    }

    // ==================== apiKeys() ====================

    public function testApiKeysReadsTheKeysEnvelope(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'keys' => [
                    [
                        'id' => 'key_1',
                        'name' => 'primary',
                        'prefix' => 'sk_test_v1_a...',
                        'isActive' => true,
                    ],
                    [
                        'id' => 'key_2',
                        'name' => 'secondary',
                        'prefix' => 'sk_test_v1_b...',
                        'isActive' => false,
                    ],
                ],
            ])),
        ]);

        $keys = $client->account()->apiKeys();

        $this->assertCount(2, $keys);
        $this->assertContainsOnlyInstancesOf(ApiKey::class, $keys);
        $this->assertSame('key_1', $keys[0]->id);
        $this->assertSame('secondary', $keys[1]->name);
        $this->assertFalse($keys[1]->isActive);
        $this->assertSame('/api/v1/account/keys', $this->history[0]['request']->getUri()->getPath());
    }

    // ==================== revokeApiKey() ====================

    public function testRevokeApiKeyUsesPatchOnTheRevokePath(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'key_1',
                'name' => 'primary',
                'revoked' => true,
                'revokedAt' => '2026-01-01T00:00:00Z',
            ])),
        ]);

        $result = $client->account()->revokeApiKey('key_1');

        $this->assertTrue($result);
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame('/api/v1/account/keys/key_1/revoke', $request->getUri()->getPath());
    }

    public function testRevokeApiKeyRequiresId(): void
    {
        $client = $this->createMockClient([]);

        $this->expectException(ValidationException::class);

        try {
            $client->account()->revokeApiKey('');
        } finally {
            $this->assertCount(0, $this->history);
        }
    }

    // ==================== getApiKey() ====================

    public function testGetApiKeyUsesTheVersionedKeyPath(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'key_1',
                'name' => 'primary',
                'prefix' => 'sk_test_v1_a...',
                'isActive' => true,
            ])),
        ]);

        $key = $client->account()->getApiKey('key_1');

        $this->assertSame('key_1', $key->id);
        $this->assertSame('/api/v1/account/keys/key_1', $this->history[0]['request']->getUri()->getPath());
    }

    // ==================== transactions() ====================

    public function testTransactionsUsesTheCreditsPath(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'transactions' => [
                    [
                        'id' => 'txn_1',
                        'amount' => -2,
                        'type' => 'usage',
                        'description' => 'SMS to +15551234567',
                    ],
                ],
            ])),
        ]);

        $transactions = $client->account()->transactions(['limit' => 5]);

        $this->assertCount(1, $transactions);
        $this->assertSame('/api/v1/credits/transactions', $this->history[0]['request']->getUri()->getPath());
    }
}
