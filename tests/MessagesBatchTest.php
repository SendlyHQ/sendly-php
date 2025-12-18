<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\AuthenticationException;
use Sendly\Exceptions\InsufficientCreditsException;
use Sendly\Exceptions\NotFoundException;
use Sendly\Exceptions\RateLimitException;
use Sendly\Exceptions\NetworkException;
use Sendly\Exceptions\SendlyException;
use ReflectionClass;

/**
 * Tests for batch messages: sendBatch(), getBatch(), listBatches()
 */
class MessagesBatchTest extends TestCase
{
    private function createMockClient(array $responses): Sendly
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new Sendly('test_api_key');

        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($client, $httpClient);

        return $client;
    }

    // ==================== sendBatch() Tests ====================

    public function testSendBatchSuccess(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'batch_id' => 'batch_123',
                'status' => 'processing',
                'total_messages' => 2,
                'total_credits' => 2,
                'created_at' => '2024-01-01T12:00:00Z',
            ])),
        ]);

        $messages = [
            ['to' => '+15551234567', 'text' => 'Message 1'],
            ['to' => '+15559876543', 'text' => 'Message 2'],
        ];

        $result = $client->messages()->sendBatch($messages);

        $this->assertIsArray($result);
        $this->assertSame('batch_123', $result['batch_id']);
        $this->assertSame('processing', $result['status']);
        $this->assertSame(2, $result['total_messages']);
        $this->assertSame(2, $result['total_credits']);
    }

    public function testSendBatchWithFrom(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'batch_id' => 'batch_123',
                'status' => 'processing',
                'total_messages' => 2,
                'total_credits' => 2,
                'from' => 'MyBrand',
                'created_at' => '2024-01-01T12:00:00Z',
            ])),
        ]);

        $messages = [
            ['to' => '+15551234567', 'text' => 'Message 1'],
            ['to' => '+15559876543', 'text' => 'Message 2'],
        ];

        $result = $client->messages()->sendBatch($messages, 'MyBrand');

        $this->assertSame('MyBrand', $result['from']);
    }

    public function testSendBatchWithEmptyArray(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Messages array cannot be empty');

        $client->messages()->sendBatch([]);
    }

    public function testSendBatchWithMissingToField(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Message at index 0 must have 'to' and 'text' fields");

        $messages = [
            ['text' => 'Message 1'], // missing 'to'
        ];

        $client->messages()->sendBatch($messages);
    }

    public function testSendBatchWithMissingTextField(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Message at index 0 must have 'to' and 'text' fields");

        $messages = [
            ['to' => '+15551234567'], // missing 'text'
        ];

        $client->messages()->sendBatch($messages);
    }

    public function testSendBatchWithInvalidPhoneFormat(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');

        $messages = [
            ['to' => '1234567890', 'text' => 'Message 1'], // invalid format
        ];

        $client->messages()->sendBatch($messages);
    }

    public function testSendBatchWithEmptyText(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Message text is required');

        $messages = [
            ['to' => '+15551234567', 'text' => ''], // empty text
        ];

        $client->messages()->sendBatch($messages);
    }

    public function testSendBatchWithTooLongText(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Message text exceeds maximum length');

        $messages = [
            ['to' => '+15551234567', 'text' => str_repeat('a', 1601)],
        ];

        $client->messages()->sendBatch($messages);
    }

    public function testSendBatchWithInvalidSecondMessage(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Message at index 1 must have 'to' and 'text' fields");

        $messages = [
            ['to' => '+15551234567', 'text' => 'Message 1'],
            ['to' => '+15559876543'], // missing text
        ];

        $client->messages()->sendBatch($messages);
    }

    public function testSendBatchAuthenticationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unauthorized',
                new Request('POST', '/messages/batch'),
                new Response(401, [], json_encode(['error' => 'Invalid API key']))
            ),
        ]);

        $this->expectException(AuthenticationException::class);

        $messages = [
            ['to' => '+15551234567', 'text' => 'Message 1'],
        ];

        $client->messages()->sendBatch($messages);
    }

    public function testSendBatchInsufficientCredits(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Payment Required',
                new Request('POST', '/messages/batch'),
                new Response(402, [], json_encode(['error' => 'Insufficient credits']))
            ),
        ]);

        $this->expectException(InsufficientCreditsException::class);

        $messages = [
            ['to' => '+15551234567', 'text' => 'Message 1'],
        ];

        $client->messages()->sendBatch($messages);
    }

    public function testSendBatchRateLimitError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Too Many Requests',
                new Request('POST', '/messages/batch'),
                new Response(429, ['Retry-After' => '45'], json_encode(['error' => 'Rate limit exceeded']))
            ),
        ]);

        $messages = [
            ['to' => '+15551234567', 'text' => 'Message 1'],
        ];

        try {
            $client->messages()->sendBatch($messages);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(45, $e->getRetryAfter());
        }
    }

    public function testSendBatchServerError(): void
    {
        $client = $this->createMockClient([
            new RequestException('Server Error', new Request('POST', '/messages/batch'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('POST', '/messages/batch'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('POST', '/messages/batch'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('POST', '/messages/batch'), new Response(500, [], json_encode(['error' => 'Server error']))),
        ]);

        $this->expectException(SendlyException::class);

        $messages = [
            ['to' => '+15551234567', 'text' => 'Message 1'],
        ];

        $client->messages()->sendBatch($messages);
    }

    public function testSendBatchNetworkError(): void
    {
        $client = $this->createMockClient([
            new ConnectException('Connection refused', new Request('POST', '/messages/batch')),
            new ConnectException('Connection refused', new Request('POST', '/messages/batch')),
            new ConnectException('Connection refused', new Request('POST', '/messages/batch')),
            new ConnectException('Connection refused', new Request('POST', '/messages/batch')),
        ]);

        $this->expectException(NetworkException::class);

        $messages = [
            ['to' => '+15551234567', 'text' => 'Message 1'],
        ];

        $client->messages()->sendBatch($messages);
    }

    // ==================== getBatch() Tests ====================

    public function testGetBatchSuccess(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'batch_id' => 'batch_123',
                'status' => 'completed',
                'total_messages' => 2,
                'successful' => 2,
                'failed' => 0,
                'total_credits' => 2,
                'created_at' => '2024-01-01T12:00:00Z',
                'completed_at' => '2024-01-01T12:05:00Z',
            ])),
        ]);

        $result = $client->messages()->getBatch('batch_123');

        $this->assertIsArray($result);
        $this->assertSame('batch_123', $result['batch_id']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(2, $result['total_messages']);
        $this->assertSame(2, $result['successful']);
        $this->assertSame(0, $result['failed']);
    }

    public function testGetBatchWithEmptyId(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Batch ID is required');

        $client->messages()->getBatch('');
    }

    public function testGetBatchNotFound(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Not Found',
                new Request('GET', '/messages/batch/invalid_id'),
                new Response(404, [], json_encode(['error' => 'Batch not found']))
            ),
        ]);

        $this->expectException(NotFoundException::class);

        $client->messages()->getBatch('invalid_id');
    }

    public function testGetBatchAuthenticationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unauthorized',
                new Request('GET', '/messages/batch/batch_123'),
                new Response(401, [], json_encode(['error' => 'Invalid API key']))
            ),
        ]);

        $this->expectException(AuthenticationException::class);

        $client->messages()->getBatch('batch_123');
    }

    public function testGetBatchRateLimitError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Too Many Requests',
                new Request('GET', '/messages/batch/batch_123'),
                new Response(429, ['Retry-After' => '20'], json_encode(['error' => 'Rate limit exceeded']))
            ),
        ]);

        try {
            $client->messages()->getBatch('batch_123');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(20, $e->getRetryAfter());
        }
    }

    public function testGetBatchServerError(): void
    {
        $client = $this->createMockClient([
            new RequestException('Server Error', new Request('GET', '/messages/batch/batch_123'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('GET', '/messages/batch/batch_123'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('GET', '/messages/batch/batch_123'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('GET', '/messages/batch/batch_123'), new Response(500, [], json_encode(['error' => 'Server error']))),
        ]);

        $this->expectException(SendlyException::class);

        $client->messages()->getBatch('batch_123');
    }

    public function testGetBatchNetworkError(): void
    {
        $client = $this->createMockClient([
            new ConnectException('Connection refused', new Request('GET', '/messages/batch/batch_123')),
            new ConnectException('Connection refused', new Request('GET', '/messages/batch/batch_123')),
            new ConnectException('Connection refused', new Request('GET', '/messages/batch/batch_123')),
            new ConnectException('Connection refused', new Request('GET', '/messages/batch/batch_123')),
        ]);

        $this->expectException(NetworkException::class);

        $client->messages()->getBatch('batch_123');
    }

    // ==================== listBatches() Tests ====================

    public function testListBatchesSuccess(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    [
                        'batch_id' => 'batch_1',
                        'status' => 'completed',
                        'total_messages' => 5,
                        'successful' => 5,
                        'failed' => 0,
                        'created_at' => '2024-01-01T12:00:00Z',
                    ],
                    [
                        'batch_id' => 'batch_2',
                        'status' => 'processing',
                        'total_messages' => 10,
                        'successful' => 5,
                        'failed' => 0,
                        'created_at' => '2024-01-01T13:00:00Z',
                    ],
                ],
                'pagination' => [
                    'total' => 2,
                    'limit' => 20,
                    'offset' => 0,
                    'has_more' => false,
                ],
            ])),
        ]);

        $result = $client->messages()->listBatches();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);
        $this->assertSame('batch_1', $result['data'][0]['batch_id']);
        $this->assertSame('batch_2', $result['data'][1]['batch_id']);
    }

    public function testListBatchesWithPagination(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    ['batch_id' => 'batch_1', 'status' => 'completed', 'total_messages' => 5, 'successful' => 5, 'failed' => 0, 'created_at' => '2024-01-01T12:00:00Z'],
                ],
                'pagination' => [
                    'total' => 100,
                    'limit' => 10,
                    'offset' => 30,
                    'has_more' => true,
                ],
            ])),
        ]);

        $result = $client->messages()->listBatches(['limit' => 10, 'offset' => 30]);

        $this->assertSame(10, $result['pagination']['limit']);
        $this->assertSame(30, $result['pagination']['offset']);
        $this->assertTrue($result['pagination']['has_more']);
    }

    public function testListBatchesWithStatusFilter(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    ['batch_id' => 'batch_1', 'status' => 'failed', 'total_messages' => 5, 'successful' => 0, 'failed' => 5, 'created_at' => '2024-01-01T12:00:00Z'],
                ],
                'pagination' => ['total' => 1, 'limit' => 20, 'offset' => 0, 'has_more' => false],
            ])),
        ]);

        $result = $client->messages()->listBatches(['status' => 'failed']);

        $this->assertSame('failed', $result['data'][0]['status']);
    }

    public function testListBatchesEmpty(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [],
                'pagination' => ['total' => 0, 'limit' => 20, 'offset' => 0, 'has_more' => false],
            ])),
        ]);

        $result = $client->messages()->listBatches();

        $this->assertIsArray($result);
        $this->assertCount(0, $result['data']);
    }

    public function testListBatchesAuthenticationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unauthorized',
                new Request('GET', '/messages/batches'),
                new Response(401, [], json_encode(['error' => 'Invalid API key']))
            ),
        ]);

        $this->expectException(AuthenticationException::class);

        $client->messages()->listBatches();
    }

    public function testListBatchesRateLimitError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Too Many Requests',
                new Request('GET', '/messages/batches'),
                new Response(429, ['Retry-After' => '10'], json_encode(['error' => 'Rate limit exceeded']))
            ),
        ]);

        try {
            $client->messages()->listBatches();
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(10, $e->getRetryAfter());
        }
    }

    public function testListBatchesNotFoundError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Not Found',
                new Request('GET', '/messages/batches'),
                new Response(404, [], json_encode(['error' => 'Endpoint not found']))
            ),
        ]);

        $this->expectException(NotFoundException::class);

        $client->messages()->listBatches();
    }

    public function testListBatchesServerError(): void
    {
        $client = $this->createMockClient([
            new RequestException('Server Error', new Request('GET', '/messages/batches'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('GET', '/messages/batches'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('GET', '/messages/batches'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('GET', '/messages/batches'), new Response(500, [], json_encode(['error' => 'Server error']))),
        ]);

        $this->expectException(SendlyException::class);

        $client->messages()->listBatches();
    }

    public function testListBatchesNetworkError(): void
    {
        $client = $this->createMockClient([
            new ConnectException('Connection refused', new Request('GET', '/messages/batches')),
            new ConnectException('Connection refused', new Request('GET', '/messages/batches')),
            new ConnectException('Connection refused', new Request('GET', '/messages/batches')),
            new ConnectException('Connection refused', new Request('GET', '/messages/batches')),
        ]);

        $this->expectException(NetworkException::class);

        $client->messages()->listBatches();
    }
}
