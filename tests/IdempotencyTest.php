<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;
use ReflectionClass;

/**
 * Tests for automatic idempotency keys - generation, retry reuse, rotation
 */
class IdempotencyTest extends TestCase
{
    private const AUTO_KEY_PATTERN = '/^sendly-php-retry-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function createMockClient(array $responses): Sendly
    {
        $this->history = [];
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($this->history));
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new Sendly('test_api_key');

        // Use reflection to inject the mock HTTP client
        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('httpClient');
        $property->setValue($client, $httpClient);

        return $client;
    }

    private function keyOfRequest(int $index): ?string
    {
        $request = $this->history[$index]['request'];

        return $request->hasHeader('Idempotency-Key')
            ? $request->getHeaderLine('Idempotency-Key')
            : null;
    }

    private function messageResponse(): Response
    {
        return new Response(200, [], json_encode([
            'message' => [
                'id' => 'msg_123',
                'to' => '+15551234567',
                'text' => 'Hello!',
                'status' => 'queued',
                'credits_used' => 1,
                'created_at' => '2024-01-01T12:00:00Z',
                'updated_at' => '2024-01-01T12:00:00Z',
            ],
        ]));
    }

    private function serverErrorException(int $statusCode = 500): RequestException
    {
        return new RequestException(
            'Internal Server Error',
            new Request('POST', '/messages'),
            new Response($statusCode, [], json_encode(['error' => 'Server error']))
        );
    }

    // ==================== Automatic key generation ====================

    public function testAutoKeyAttachedToPostRequests(): void
    {
        $client = $this->createMockClient([$this->messageResponse()]);

        $client->messages()->send('+15551234567', 'Hello!');

        $key = $this->keyOfRequest(0);
        $this->assertNotNull($key);
        $this->assertMatchesRegularExpression(self::AUTO_KEY_PATTERN, $key);
        $this->assertLessThanOrEqual(255, strlen($key));
    }

    public function testNoKeyOnGetRequests(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [],
                'pagination' => ['total' => 0, 'limit' => 20, 'offset' => 0, 'has_more' => false],
            ])),
        ]);

        $client->messages()->list(['limit' => 10]);

        $this->assertNull($this->keyOfRequest(0));
    }

    public function testNoKeyOnDeleteRequests(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'schd_x',
                'status' => 'cancelled',
                'creditsRefunded' => 1,
            ])),
        ]);

        $client->messages()->cancelScheduled('schd_x');

        $this->assertNull($this->keyOfRequest(0));
    }

    public function testNoAutoKeyOnBatchSend(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'batchId' => 'batch_x',
                'queued' => 1,
                'failed' => 0,
                'creditsUsed' => 1,
                'messages' => [],
            ])),
        ]);

        $client->messages()->sendBatch([
            ['to' => '+15551234567', 'text' => 'Hi!'],
        ]);

        $this->assertNull($this->keyOfRequest(0));
    }

    public function testAutoKeyOnMediaUpload(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'media' => ['id' => 'med_x', 'url' => 'https://cdn.example/x.jpg'],
            ])),
        ]);

        $filePath = tempnam(sys_get_temp_dir(), 'sendly_php_sdk_test_');
        file_put_contents($filePath, 'fake-image-bytes');

        try {
            $client->media()->upload($filePath, 'image/jpeg');
        } finally {
            unlink($filePath);
        }

        $this->assertMatchesRegularExpression(self::AUTO_KEY_PATTERN, (string) $this->keyOfRequest(0));
    }

    public function testDistinctKeysAcrossLogicalRequests(): void
    {
        $client = $this->createMockClient([
            $this->messageResponse(),
            $this->messageResponse(),
        ]);

        $client->messages()->send('+15551234567', 'First');
        $client->messages()->send('+15551234567', 'Second');

        $first = $this->keyOfRequest(0);
        $second = $this->keyOfRequest(1);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
    }

    // ==================== Retry behavior ====================

    public function testKeyReusedAcrossTimeoutRetry(): void
    {
        $client = $this->createMockClient([
            new ConnectException(
                'cURL error 28: Operation timed out',
                new Request('POST', '/messages')
            ),
            $this->messageResponse(),
        ]);

        $message = $client->messages()->send('+15551234567', 'Hello!');

        $this->assertSame('msg_123', $message->id);
        $this->assertCount(2, $this->history);
        $this->assertSame($this->keyOfRequest(0), $this->keyOfRequest(1));
    }

    public function testKeyReusedAcrossNetworkErrorRetry(): void
    {
        $client = $this->createMockClient([
            new ConnectException(
                'Connection refused',
                new Request('POST', '/messages')
            ),
            $this->messageResponse(),
        ]);

        $message = $client->messages()->send('+15551234567', 'Hello!');

        $this->assertSame('msg_123', $message->id);
        $this->assertCount(2, $this->history);
        $this->assertSame($this->keyOfRequest(0), $this->keyOfRequest(1));
    }

    public function testKeyRotatedAfterServerErrorRetry(): void
    {
        $client = $this->createMockClient([
            $this->serverErrorException(500),
            $this->messageResponse(),
        ]);

        $message = $client->messages()->send('+15551234567', 'Hello!');

        $this->assertSame('msg_123', $message->id);
        $this->assertCount(2, $this->history);
        $first = $this->keyOfRequest(0);
        $second = $this->keyOfRequest(1);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
    }

    public function testRotatedKeyKeptAcrossSubsequentTimeout(): void
    {
        $client = $this->createMockClient([
            $this->serverErrorException(500),
            new ConnectException(
                'cURL error 28: Operation timed out',
                new Request('POST', '/messages')
            ),
            $this->messageResponse(),
        ]);

        $message = $client->messages()->send('+15551234567', 'Hello!');

        $this->assertSame('msg_123', $message->id);
        $this->assertCount(3, $this->history);
        $first = $this->keyOfRequest(0);
        $second = $this->keyOfRequest(1);
        $third = $this->keyOfRequest(2);
        $this->assertNotSame($first, $second);
        $this->assertSame($second, $third);
    }

    public function testKeyKeptAcrossNonServerErrorRetry(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Conflict',
                new Request('POST', '/messages'),
                new Response(409, [], json_encode(['error' => 'Resource busy']))
            ),
            $this->messageResponse(),
        ]);

        $message = $client->messages()->send('+15551234567', 'Hello!');

        $this->assertSame('msg_123', $message->id);
        $this->assertCount(2, $this->history);
        $this->assertSame($this->keyOfRequest(0), $this->keyOfRequest(1));
    }

    public function testKeyRotatedOnServerErrorForMediaUpload(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Bad Gateway',
                new Request('POST', '/media'),
                new Response(502, [], json_encode(['error' => 'Server error']))
            ),
            new Response(200, [], json_encode([
                'media' => ['id' => 'med_x', 'url' => 'https://cdn.example/x.jpg'],
            ])),
        ]);

        $filePath = tempnam(sys_get_temp_dir(), 'sendly_php_sdk_test_');
        file_put_contents($filePath, 'fake-image-bytes');

        try {
            $client->media()->upload($filePath);
        } finally {
            unlink($filePath);
        }

        $this->assertCount(2, $this->history);
        $first = $this->keyOfRequest(0);
        $second = $this->keyOfRequest(1);
        $this->assertMatchesRegularExpression(self::AUTO_KEY_PATTERN, (string) $first);
        $this->assertMatchesRegularExpression(self::AUTO_KEY_PATTERN, (string) $second);
        $this->assertNotSame($first, $second);
    }

    // ==================== Caller-supplied keys ====================

    public function testCallerKeySentVerbatim(): void
    {
        $client = $this->createMockClient([$this->messageResponse()]);

        $client->messages()->send('+15551234567', 'Hello!', idempotencyKey: 'order-4821-shipped');

        $this->assertSame('order-4821-shipped', $this->keyOfRequest(0));
    }

    public function testCallerKeyNeverRotatedAcrossServerErrorRetry(): void
    {
        $client = $this->createMockClient([
            $this->serverErrorException(500),
            $this->messageResponse(),
        ]);

        $client->messages()->send('+15551234567', 'Hello!', idempotencyKey: 'order-4821-shipped');

        $this->assertCount(2, $this->history);
        $this->assertSame('order-4821-shipped', $this->keyOfRequest(0));
        $this->assertSame('order-4821-shipped', $this->keyOfRequest(1));
    }

    public function testCallerKeyReusedAcrossTimeoutRetry(): void
    {
        $client = $this->createMockClient([
            new ConnectException(
                'cURL error 28: Operation timed out',
                new Request('POST', '/messages')
            ),
            $this->messageResponse(),
        ]);

        $client->messages()->send('+15551234567', 'Hello!', idempotencyKey: 'signup-otp-user-99');

        $this->assertCount(2, $this->history);
        $this->assertSame('signup-otp-user-99', $this->keyOfRequest(0));
        $this->assertSame('signup-otp-user-99', $this->keyOfRequest(1));
    }

    public function testCallerKeyAcceptedOnSendBatch(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'batchId' => 'batch_x',
                'queued' => 1,
                'failed' => 0,
                'creditsUsed' => 1,
                'messages' => [],
            ])),
        ]);

        $client->messages()->sendBatch(
            [['to' => '+15551234567', 'text' => 'Hi!']],
            idempotencyKey: 'campaign-77-wave-1'
        );

        $this->assertSame('campaign-77-wave-1', $this->keyOfRequest(0));
    }

    public function testCallerKeyAcceptedOnSchedule(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'schd_x',
                'to' => '+15551234567',
                'text' => 'Reminder!',
                'status' => 'scheduled',
                'scheduledAt' => '2026-09-01T10:00:00Z',
            ])),
        ]);

        $client->messages()->schedule(
            '+15551234567',
            'Reminder!',
            '2026-09-01T10:00:00Z',
            idempotencyKey: 'reminder-visit-31'
        );

        $this->assertSame('reminder-visit-31', $this->keyOfRequest(0));
    }

    public function testCallerKeyAcceptedOnSendGroup(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'msg_x',
                'group_message_id' => 'grp_x',
                'to' => ['+14155551234', '+14155555678'],
                'status' => 'queued',
            ])),
        ]);

        $client->messages()->sendGroup([
            'to' => ['+14155551234', '+14155555678'],
            'text' => 'Team sync at noon',
            'idempotencyKey' => 'standup-ping-0823',
        ]);

        $this->assertSame('standup-ping-0823', $this->keyOfRequest(0));
    }

    public function testCallerKeyAcceptedViaSendOptionsArray(): void
    {
        $client = $this->createMockClient([$this->messageResponse()]);

        $client->messages()->send([
            'to' => '+15551234567',
            'text' => 'Hello!',
            'idempotencyKey' => 'array-style-key-1',
        ]);

        $this->assertSame('array-style-key-1', $this->keyOfRequest(0));
    }

    public function testCallerKeyAcceptedOnWhatsAppSendBranch(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'msg_wa',
                'channel' => 'whatsapp',
                'to' => '+15551234567',
                'from' => '+15559876543',
                'status' => 'queued',
                'whatsapp' => ['kind' => 'text'],
            ])),
        ]);

        $client->messages()->send([
            'channel' => 'whatsapp',
            'to' => '+15551234567',
            'from' => '+15559876543',
            'text' => 'Hello!',
            'idempotencyKey' => 'wa-hello-1',
        ]);

        $this->assertSame('wa-hello-1', $this->keyOfRequest(0));
    }

    public function testCallerKeyAcceptedOnRcsSendBranch(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'msg_rcs',
                'channel' => 'rcs',
                'to' => '+15551234567',
                'status' => 'queued',
            ])),
        ]);

        $client->messages()->send([
            'channel' => 'rcs',
            'to' => '+15551234567',
            'text' => 'Hello!',
            'idempotencyKey' => 'rcs-hello-1',
        ]);

        $this->assertSame('rcs-hello-1', $this->keyOfRequest(0));
    }

    public function testEmptyCallerKeyFallsBackToAuto(): void
    {
        $client = $this->createMockClient([$this->messageResponse()]);

        $client->messages()->send('+15551234567', 'Hello!', idempotencyKey: '');

        $this->assertMatchesRegularExpression(self::AUTO_KEY_PATTERN, (string) $this->keyOfRequest(0));
    }

    public function testWhitespaceOnlyCallerKeyFallsBackToAuto(): void
    {
        $client = $this->createMockClient([$this->messageResponse()]);

        $client->messages()->send('+15551234567', 'Hello!', idempotencyKey: '   ');

        $this->assertMatchesRegularExpression(self::AUTO_KEY_PATTERN, (string) $this->keyOfRequest(0));
    }

    public function testNonAsciiCallerKeyRejectedWithoutNetworkCall(): void
    {
        $client = $this->createMockClient([$this->messageResponse()]);

        try {
            $client->messages()->send('+15551234567', 'Hello!', idempotencyKey: "Заказ-42");
            $this->fail('Expected ValidationException to be thrown');
        } catch (ValidationException $e) {
            $this->assertSame('Idempotency key must be 1-255 printable ASCII characters', $e->getMessage());
        }

        $this->assertCount(0, $this->history);
    }

    public function testOversizedCallerKeyRejectedWithoutNetworkCall(): void
    {
        $client = $this->createMockClient([$this->messageResponse()]);

        try {
            $client->messages()->send('+15551234567', 'Hello!', idempotencyKey: str_repeat('k', 256));
            $this->fail('Expected ValidationException to be thrown');
        } catch (ValidationException $e) {
            $this->assertSame('Idempotency key must be 1-255 printable ASCII characters', $e->getMessage());
        }

        $this->assertCount(0, $this->history);
    }
}
