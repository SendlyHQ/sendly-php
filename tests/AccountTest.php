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

    // ==================== get() ====================

    /**
     * @return array<string, mixed>
     */
    private function accountBody(array $overrides = []): array
    {
        return array_merge([
            'user' => [
                'id' => 'user_1',
                'email' => 'a@b.co',
                'createdAt' => '2026-01-02T03:04:05Z',
            ],
            'organization' => ['id' => 'org_1', 'name' => 'Acme', 'isPersonal' => false],
            'credits' => ['balance' => 500, 'reservedBalance' => '0'],
            'verification' => [
                'status' => 'verified',
                'type' => 'toll_free',
                'region' => 'us',
                'submittedAt' => '2026-01-03T00:00:00Z',
                'updatedAt' => '2026-01-05T00:00:00Z',
            ],
            'apiKey' => [
                'id' => 'key_1',
                'name' => 'primary',
                'type' => 'live',
                'scopes' => ['sms:send'],
                'createdAt' => '2026-01-02T03:04:05Z',
                'lastUsedAt' => null,
            ],
            'limits' => ['messagesPerMinute' => 60, 'messagesPerDay' => 10000],
        ], $overrides);
    }

    public function testGetReadsTheUserTheApiNestsItUnder(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->accountBody())),
        ]);

        $account = $client->account()->get();

        $this->assertSame('/api/v1/account', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('user_1', $account->id);
        $this->assertSame('a@b.co', $account->email);
        $this->assertSame('2026-01-02T03:04:05+00:00', $account->createdAt->format(DATE_ATOM));
        $this->assertSame(['id' => 'org_1', 'name' => 'Acme', 'isPersonal' => false], $account->organization);
        $this->assertSame(['balance' => 500, 'reservedBalance' => 0], $account->credits);
        $this->assertSame('key_1', $account->apiKey['id']);
        $this->assertSame(['sms:send'], $account->apiKey['scopes']);
    }

    public function testGetReadsTheWorkspaceBalanceAsIntegers(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->accountBody([
                'credits' => ['balance' => '0', 'reservedBalance' => '0'],
            ]))),
            new Response(200, [], json_encode($this->accountBody([
                'credits' => ['balance' => 1200, 'reservedBalance' => 40],
            ]))),
        ]);

        $this->assertSame(['balance' => 0, 'reservedBalance' => 0], $client->account()->get()->credits);
        $this->assertSame(['balance' => 1200, 'reservedBalance' => 40], $client->account()->get()->credits);
    }

    public function testGetReadsTheBusinessVerification(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->accountBody())),
        ]);

        $verification = $client->account()->get()->verification;

        $this->assertSame('verified', $verification->status);
        $this->assertSame('toll_free', $verification->type);
        $this->assertSame('us', $verification->region);
        $this->assertSame('2026-01-03T00:00:00+00:00', $verification->submittedAt->format(DATE_ATOM));
        $this->assertSame('2026-01-05T00:00:00+00:00', $verification->updatedAt->format(DATE_ATOM));
        $this->assertTrue($verification->isVerified());
        $this->assertTrue($verification->isFullyVerified());
    }

    public function testGetWithoutAVerificationIsNotVerified(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->accountBody(['verification' => null, 'organization' => null]))),
        ]);

        $account = $client->account()->get();

        $this->assertNull($account->verification->status);
        $this->assertNull($account->verification->submittedAt);
        $this->assertFalse($account->verification->isVerified());
        $this->assertFalse($account->verification->isFullyVerified());
        $this->assertNull($account->organization);
    }

    public function testGetPendingVerificationIsNotVerified(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->accountBody([
                'verification' => ['status' => 'action_required', 'type' => 'toll_free', 'region' => 'us'],
            ]))),
        ]);

        $verification = $client->account()->get()->verification;

        $this->assertSame('action_required', $verification->status);
        $this->assertFalse($verification->isVerified());
        $this->assertFalse($verification->isFullyVerified());
    }

    public function testGetReadsTheLimitsTheApiReports(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->accountBody([
                'limits' => ['messagesPerMinute' => 60, 'messagesPerDay' => 100],
            ]))),
        ]);

        $limits = $client->account()->get()->limits;

        $this->assertSame(60, $limits->messagesPerMinute);
        $this->assertSame(10, $limits->messagesPerSecond);
        $this->assertSame(100, $limits->messagesPerDay);
        $this->assertSame(10000, $limits->maxBatchSize);
    }

    public function testFlatAccountFieldsStillMap(): void
    {
        $account = new \Sendly\Account([
            'id' => 'user_9',
            'email' => 'flat@b.co',
            'created_at' => '2025-05-05T05:05:05Z',
            'verification' => ['email_verified' => true, 'phone_verified' => true, 'identity_verified' => true],
            'limits' => ['messages_per_second' => 5, 'max_batch_size' => 250],
        ]);

        $this->assertSame('user_9', $account->id);
        $this->assertSame('flat@b.co', $account->email);
        $this->assertSame('2025', $account->createdAt->format('Y'));
        $this->assertTrue($account->verification->isFullyVerified());
        $this->assertSame(5, $account->limits->messagesPerSecond);
        $this->assertSame(250, $account->limits->maxBatchSize);
    }

    // ==================== credits() ====================

    public function testCreditsReadsTheReservedBalance(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'balance' => 500,
                'reservedBalance' => 40,
                'availableBalance' => 460,
                'billingMode' => 'prepaid',
                'recentTransactions' => [],
            ])),
        ]);

        $credits = $client->account()->credits();

        $this->assertSame('/api/v1/account/credits', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame(500, $credits->balance);
        $this->assertSame(40, $credits->reservedCredits);
        $this->assertSame(40, $credits->reservedBalance);
        $this->assertSame(460, $credits->availableBalance);
        $this->assertSame('prepaid', $credits->billingMode);
        $this->assertTrue($credits->hasCredits());
        $this->assertSame(40, $credits->toArray()['reserved_balance']);
        $this->assertSame(40, $credits->toArray()['reserved_credits']);
        $this->assertSame('prepaid', $credits->toArray()['billing_mode']);
    }

    public function testCreditsReadsAPooledBalance(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'balance' => -20,
                'reservedBalance' => 10,
                'availableBalance' => 70,
                'billingMode' => 'pooled',
                'recentTransactions' => [],
            ])),
        ]);

        $credits = $client->account()->credits();

        $this->assertSame(-20, $credits->balance);
        $this->assertSame(10, $credits->reservedBalance);
        $this->assertSame(70, $credits->availableBalance);
        $this->assertSame('pooled', $credits->billingMode);
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
                        'type' => 'live',
                        'prefix' => 'sk_live_v1_a...',
                        'scopes' => ['sms:send'],
                        'permissions' => ['sms:send'],
                        'isActive' => true,
                        'isRevoked' => false,
                        'createdAt' => '2026-09-01T10:00:00.000Z',
                        'lastUsedAt' => null,
                        'expiresAt' => null,
                    ],
                    [
                        'id' => 'key_2',
                        'name' => 'secondary',
                        'type' => 'test',
                        'prefix' => 'sk_test_v1_b...',
                        'scopes' => null,
                        'permissions' => null,
                        'isActive' => false,
                        'isRevoked' => true,
                        'createdAt' => '2026-09-02T10:00:00.000Z',
                        'lastUsedAt' => null,
                        'expiresAt' => null,
                    ],
                ],
            ])),
        ]);

        $keys = $client->account()->apiKeys();

        $this->assertCount(2, $keys);
        $this->assertContainsOnlyInstancesOf(ApiKey::class, $keys);
        $this->assertSame('key_1', $keys[0]->id);
        $this->assertSame('live', $keys[0]->type);
        $this->assertSame(['sms:send'], $keys[0]->scopes);
        $this->assertTrue($keys[0]->isLive());
        $this->assertSame('secondary', $keys[1]->name);
        $this->assertSame('test', $keys[1]->type);
        $this->assertSame([], $keys[1]->scopes);
        $this->assertFalse($keys[1]->isLive());
        $this->assertFalse($keys[1]->isActive);
        $this->assertSame('/api/v1/account/keys', $this->history[0]['request']->getUri()->getPath());
    }

    public function testGetApiKeyReadsTypeScopesAndRevokedAt(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'key_2',
                'name' => 'retired',
                'type' => 'test',
                'prefix' => 'sk_test_v1_b...',
                'scopes' => ['sms:read'],
                'isActive' => false,
                'createdAt' => '2026-09-02T10:00:00.000Z',
                'lastUsedAt' => '2026-09-10T08:00:00.000Z',
                'expiresAt' => null,
                'revokedAt' => '2026-09-20T12:30:00.000Z',
            ])),
        ]);

        $key = $client->account()->getApiKey('key_2');

        $this->assertSame('test', $key->type);
        $this->assertSame(['sms:read'], $key->scopes);
        $this->assertFalse($key->isActive);
        $this->assertNotNull($key->revokedAt);
        $this->assertSame('2026-09-20T12:30:00+00:00', $key->revokedAt->format(DATE_ATOM));
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

    // ==================== createApiKey() ====================

    /**
     * @return array<string, mixed>
     */
    private function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function createdKeyBody(): array
    {
        return [
            'id' => 'key_1',
            'name' => 'Production',
            'key' => 'sk_live_v1_key_AbCdEfGhIjKlMnOp_x9Yz',
            'keyPrefix' => 'sk_live_v1_k',
            'type' => 'live',
            'createdAt' => '2026-09-25T10:00:00.000Z',
            'expiresAt' => null,
            'apiKey' => [
                'id' => 'key_1',
                'name' => 'Production',
                'type' => 'live',
                'prefix' => 'sk_live_v1_k...',
                'scopes' => ['sms:send'],
                'permissions' => ['sms:send'],
                'isActive' => true,
                'isRevoked' => false,
                'createdAt' => '2026-09-25T10:00:00.000Z',
                'lastUsedAt' => null,
                'expiresAt' => null,
            ],
        ];
    }

    public function testCreateApiKeySendsTypeAndScopes(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->createdKeyBody())),
        ]);

        $result = $client->account()->createApiKey('Production', [
            'type' => 'live',
            'scopes' => ['sms:send'],
        ]);

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/account/keys', $request->getUri()->getPath());
        $this->assertSame(
            ['name' => 'Production', 'type' => 'live', 'scopes' => ['sms:send']],
            $this->sentBody()
        );
        $this->assertSame('sk_live_v1_key_AbCdEfGhIjKlMnOp_x9Yz', $result['key']);
        $this->assertInstanceOf(ApiKey::class, $result['apiKey']);
        $this->assertSame('key_1', $result['apiKey']->id);
        $this->assertSame('sk_live_v1_k...', $result['apiKey']->prefix);
    }

    public function testCreateApiKeySendsScopesAsAJsonArrayWhateverTheirKeys(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->createdKeyBody())),
        ]);

        $client->account()->createApiKey('CI', [
            'scopes' => array_unique(['sms:send', 'sms:send', 'sms:read']),
        ]);

        $this->assertSame(
            '{"name":"CI","scopes":["sms:send","sms:read"]}',
            (string) $this->history[0]['request']->getBody()
        );
    }

    public function testCreateApiKeyWithoutOptionsSendsOnlyTheName(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->createdKeyBody())),
        ]);

        $client->account()->createApiKey('CI');

        $this->assertSame(['name' => 'CI'], $this->sentBody());
    }

    public function testCreateApiKeyKeepsSendingExpiresAt(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->createdKeyBody())),
        ]);

        $client->account()->createApiKey('CI', [
            'type' => 'test',
            'expiresAt' => '2027-01-01T00:00:00Z',
        ]);

        $this->assertSame(
            ['name' => 'CI', 'type' => 'test', 'expires_at' => '2027-01-01T00:00:00Z'],
            $this->sentBody()
        );
    }

    public function testCreateApiKeyRejectsAnUnknownTypeBeforeSending(): void
    {
        $client = $this->createMockClient([]);

        try {
            $client->account()->createApiKey('x', ['type' => 'admin']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString("'test' or 'live'", $e->getMessage());
        }

        $this->assertCount(0, $this->history);
    }

    public function testCreateApiKeyReadsAFlatResponseWithoutTheApiKeyObject(): void
    {
        $flat = $this->createdKeyBody();
        unset($flat['apiKey'], $flat['expiresAt']);

        $client = $this->createMockClient([
            new Response(200, [], json_encode($flat)),
        ]);

        $result = $client->account()->createApiKey('Production', ['type' => 'live']);

        $this->assertSame('sk_live_v1_key_AbCdEfGhIjKlMnOp_x9Yz', $result['key']);
        $this->assertSame('key_1', $result['apiKey']->id);
        $this->assertSame('sk_live_v1_k', $result['apiKey']->prefix);
    }

    // ==================== transactions() ====================

    public function testTransactionTypeConstantsCoverWhatTheLedgerRecords(): void
    {
        $this->assertSame('purchase', \Sendly\CreditTransaction::TYPE_PURCHASE);
        $this->assertSame('usage', \Sendly\CreditTransaction::TYPE_USAGE);
        $this->assertSame('refund', \Sendly\CreditTransaction::TYPE_REFUND);
        $this->assertSame('bonus', \Sendly\CreditTransaction::TYPE_BONUS);
        $this->assertSame('transfer', \Sendly\CreditTransaction::TYPE_TRANSFER);
        $this->assertSame('admin_grant', \Sendly\CreditTransaction::TYPE_ADMIN_GRANT);
        $this->assertSame('admin_seed', \Sendly\CreditTransaction::TYPE_ADMIN_SEED);
    }

    public function testTransactionsReadTheLedgerRowsTheApiSends(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'transactions' => [
                    [
                        'id' => 'txn_1',
                        'amount' => -500,
                        'balance_after' => 1500,
                        'type' => 'transfer',
                        'description' => 'Transfer to workspace Acme West',
                        'created_at' => '2026-09-20T09:00:00.000Z',
                    ],
                    [
                        'id' => 'txn_2',
                        'amount' => 1000,
                        'balance_after' => 2000,
                        'type' => 'admin_grant',
                        'description' => null,
                        'created_at' => '2026-09-19T09:00:00.000Z',
                    ],
                ],
            ])),
        ]);

        $transactions = $client->account()->transactions(['type' => 'transfer']);

        $this->assertSame(\Sendly\CreditTransaction::TYPE_TRANSFER, $transactions[0]->type);
        $this->assertSame(1500, $transactions[0]->balanceAfter);
        $this->assertTrue($transactions[0]->isDebit());
        $this->assertSame(\Sendly\CreditTransaction::TYPE_ADMIN_GRANT, $transactions[1]->type);
        $this->assertNull($transactions[1]->description);
        $this->assertStringContainsString('type=transfer', $this->history[0]['request']->getUri()->getQuery());
    }

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
