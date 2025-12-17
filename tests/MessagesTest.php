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
use Sendly\Message;
use Sendly\MessageList;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\AuthenticationException;
use Sendly\Exceptions\InsufficientCreditsException;
use Sendly\Exceptions\NotFoundException;
use Sendly\Exceptions\RateLimitException;
use Sendly\Exceptions\NetworkException;
use Sendly\Exceptions\SendlyException;
use ReflectionClass;

/**
 * Tests for Messages resource: send(), list(), get(), each()
 */
class MessagesTest extends TestCase
{
    private function createMockClient(array $responses): Sendly
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new Sendly('test_api_key');

        // Use reflection to inject the mock HTTP client
        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($client, $httpClient);

        return $client;
    }

    // ==================== send() Tests ====================

    public function testSendMessageSuccess(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'message' => [
                    'id' => 'msg_123',
                    'to' => '+15551234567',
                    'text' => 'Test message',
                    'status' => 'queued',
                    'credits_used' => 1,
                    'created_at' => '2024-01-01T12:00:00Z',
                    'updated_at' => '2024-01-01T12:00:00Z',
                ],
            ])),
        ]);

        $message = $client->messages()->send('+15551234567', 'Test message');

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('msg_123', $message->id);
        $this->assertSame('+15551234567', $message->to);
        $this->assertSame('Test message', $message->text);
        $this->assertSame('queued', $message->status);
        $this->assertSame(1, $message->creditsUsed);
    }

    public function testSendMessageWithInvalidPhoneFormat(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');

        $client->messages()->send('1234567890', 'Test message');
    }

    public function testSendMessageWithEmptyPhone(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');

        $client->messages()->send('', 'Test message');
    }

    public function testSendMessageWithInvalidPhonePrefix(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');

        $client->messages()->send('+01234567890', 'Test message'); // starts with +0
    }

    public function testSendMessageWithEmptyText(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Message text is required');

        $client->messages()->send('+15551234567', '');
    }

    public function testSendMessageWithTooLongText(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Message text exceeds maximum length');

        $longText = str_repeat('a', 1601);
        $client->messages()->send('+15551234567', $longText);
    }

    public function testSendMessageWithMaxLengthText(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'message' => [
                    'id' => 'msg_123',
                    'to' => '+15551234567',
                    'text' => str_repeat('a', 1600),
                    'status' => 'queued',
                    'credits_used' => 10,
                    'created_at' => '2024-01-01T12:00:00Z',
                    'updated_at' => '2024-01-01T12:00:00Z',
                ],
            ])),
        ]);

        $longText = str_repeat('a', 1600);
        $message = $client->messages()->send('+15551234567', $longText);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(1600, strlen($message->text));
    }

    public function testSendMessageAuthenticationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unauthorized',
                new Request('POST', '/messages'),
                new Response(401, [], json_encode(['error' => 'Invalid API key']))
            ),
        ]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key');

        $client->messages()->send('+15551234567', 'Test message');
    }

    public function testSendMessageInsufficientCredits(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Payment Required',
                new Request('POST', '/messages'),
                new Response(402, [], json_encode(['error' => 'Insufficient credits']))
            ),
        ]);

        $this->expectException(InsufficientCreditsException::class);
        $this->expectExceptionMessage('Insufficient credits');

        $client->messages()->send('+15551234567', 'Test message');
    }

    public function testSendMessageRateLimitError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Too Many Requests',
                new Request('POST', '/messages'),
                new Response(429, ['Retry-After' => '60'], json_encode(['error' => 'Rate limit exceeded']))
            ),
        ]);

        try {
            $client->messages()->send('+15551234567', 'Test message');
            $this->fail('Expected RateLimitException to be thrown');
        } catch (RateLimitException $e) {
            $this->assertSame('Rate limit exceeded', $e->getMessage());
            $this->assertSame(60, $e->getRetryAfter());
            $this->assertSame(429, $e->getCode());
        }
    }

    public function testSendMessageServerError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Internal Server Error',
                new Request('POST', '/messages'),
                new Response(500, [], json_encode(['error' => 'Server error']))
            ),
            new RequestException(
                'Internal Server Error',
                new Request('POST', '/messages'),
                new Response(500, [], json_encode(['error' => 'Server error']))
            ),
            new RequestException(
                'Internal Server Error',
                new Request('POST', '/messages'),
                new Response(500, [], json_encode(['error' => 'Server error']))
            ),
            new RequestException(
                'Internal Server Error',
                new Request('POST', '/messages'),
                new Response(500, [], json_encode(['error' => 'Server error']))
            ),
        ]);

        $this->expectException(SendlyException::class);
        $this->expectExceptionMessage('Server error');

        $client->messages()->send('+15551234567', 'Test message');
    }

    public function testSendMessageNetworkError(): void
    {
        $client = $this->createMockClient([
            new ConnectException(
                'Connection refused',
                new Request('POST', '/messages')
            ),
            new ConnectException(
                'Connection refused',
                new Request('POST', '/messages')
            ),
            new ConnectException(
                'Connection refused',
                new Request('POST', '/messages')
            ),
            new ConnectException(
                'Connection refused',
                new Request('POST', '/messages')
            ),
        ]);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('Connection failed');

        $client->messages()->send('+15551234567', 'Test message');
    }

    // ==================== list() Tests ====================

    public function testListMessagesSuccess(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    [
                        'id' => 'msg_1',
                        'to' => '+15551234567',
                        'text' => 'Message 1',
                        'status' => 'delivered',
                        'credits_used' => 1,
                        'created_at' => '2024-01-01T12:00:00Z',
                        'updated_at' => '2024-01-01T12:00:00Z',
                    ],
                    [
                        'id' => 'msg_2',
                        'to' => '+15559876543',
                        'text' => 'Message 2',
                        'status' => 'sent',
                        'credits_used' => 1,
                        'created_at' => '2024-01-01T12:01:00Z',
                        'updated_at' => '2024-01-01T12:01:00Z',
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

        $list = $client->messages()->list();

        $this->assertInstanceOf(MessageList::class, $list);
        $this->assertSame(2, $list->count());
        $this->assertSame(2, $list->total);
        $this->assertSame(20, $list->limit);
        $this->assertSame(0, $list->offset);
        $this->assertFalse($list->hasMore);
        $this->assertFalse($list->isEmpty());
    }

    public function testListMessagesWithPagination(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => 'msg_1', 'to' => '+15551234567', 'text' => 'Message 1', 'status' => 'delivered', 'credits_used' => 1, 'created_at' => '2024-01-01T12:00:00Z', 'updated_at' => '2024-01-01T12:00:00Z'],
                ],
                'pagination' => [
                    'total' => 100,
                    'limit' => 10,
                    'offset' => 20,
                    'has_more' => true,
                ],
            ])),
        ]);

        $list = $client->messages()->list(['limit' => 10, 'offset' => 20]);

        $this->assertSame(1, $list->count());
        $this->assertSame(100, $list->total);
        $this->assertSame(10, $list->limit);
        $this->assertSame(20, $list->offset);
        $this->assertTrue($list->hasMore);
    }

    public function testListMessagesWithStatusFilter(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => 'msg_1', 'to' => '+15551234567', 'text' => 'Message 1', 'status' => 'delivered', 'credits_used' => 1, 'created_at' => '2024-01-01T12:00:00Z', 'updated_at' => '2024-01-01T12:00:00Z'],
                ],
                'pagination' => [
                    'total' => 1,
                    'limit' => 20,
                    'offset' => 0,
                    'has_more' => false,
                ],
            ])),
        ]);

        $list = $client->messages()->list(['status' => 'delivered']);

        $this->assertSame(1, $list->count());
        $this->assertSame('delivered', $list->first()->status);
    }

    public function testListMessagesWithPhoneFilter(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => 'msg_1', 'to' => '+15551234567', 'text' => 'Message 1', 'status' => 'delivered', 'credits_used' => 1, 'created_at' => '2024-01-01T12:00:00Z', 'updated_at' => '2024-01-01T12:00:00Z'],
                ],
                'pagination' => [
                    'total' => 1,
                    'limit' => 20,
                    'offset' => 0,
                    'has_more' => false,
                ],
            ])),
        ]);

        $list = $client->messages()->list(['to' => '+15551234567']);

        $this->assertSame(1, $list->count());
        $this->assertSame('+15551234567', $list->first()->to);
    }

    public function testListMessagesEmpty(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [],
                'pagination' => [
                    'total' => 0,
                    'limit' => 20,
                    'offset' => 0,
                    'has_more' => false,
                ],
            ])),
        ]);

        $list = $client->messages()->list();

        $this->assertSame(0, $list->count());
        $this->assertTrue($list->isEmpty());
        $this->assertNull($list->first());
        $this->assertNull($list->last());
    }

    public function testListMessagesAuthenticationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unauthorized',
                new Request('GET', '/messages'),
                new Response(401, [], json_encode(['error' => 'Invalid API key']))
            ),
        ]);

        $this->expectException(AuthenticationException::class);
        $client->messages()->list();
    }

    // ==================== get() Tests ====================

    public function testGetMessageSuccess(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    'id' => 'msg_123',
                    'to' => '+15551234567',
                    'text' => 'Test message',
                    'status' => 'delivered',
                    'credits_used' => 1,
                    'created_at' => '2024-01-01T12:00:00Z',
                    'updated_at' => '2024-01-01T12:00:00Z',
                    'delivered_at' => '2024-01-01T12:01:00Z',
                ],
            ])),
        ]);

        $message = $client->messages()->get('msg_123');

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('msg_123', $message->id);
        $this->assertSame('delivered', $message->status);
        $this->assertTrue($message->isDelivered());
        $this->assertFalse($message->isFailed());
        $this->assertFalse($message->isPending());
    }

    public function testGetMessageWithEmptyId(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Message ID is required');

        $client->messages()->get('');
    }

    public function testGetMessageNotFound(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Not Found',
                new Request('GET', '/messages/invalid_id'),
                new Response(404, [], json_encode(['error' => 'Message not found']))
            ),
        ]);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Message not found');

        $client->messages()->get('invalid_id');
    }

    public function testGetMessageAuthenticationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unauthorized',
                new Request('GET', '/messages/msg_123'),
                new Response(401, [], json_encode(['error' => 'Invalid API key']))
            ),
        ]);

        $this->expectException(AuthenticationException::class);
        $client->messages()->get('msg_123');
    }

    // ==================== each() Tests ====================

    public function testEachMessagesSinglePage(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => 'msg_1', 'to' => '+15551234567', 'text' => 'Message 1', 'status' => 'delivered', 'credits_used' => 1, 'created_at' => '2024-01-01T12:00:00Z', 'updated_at' => '2024-01-01T12:00:00Z'],
                    ['id' => 'msg_2', 'to' => '+15559876543', 'text' => 'Message 2', 'status' => 'sent', 'credits_used' => 1, 'created_at' => '2024-01-01T12:01:00Z', 'updated_at' => '2024-01-01T12:01:00Z'],
                ],
                'pagination' => ['total' => 2, 'limit' => 100, 'offset' => 0, 'has_more' => false],
            ])),
        ]);

        $messages = iterator_to_array($client->messages()->each());

        $this->assertCount(2, $messages);
        $this->assertSame('msg_1', $messages[0]->id);
        $this->assertSame('msg_2', $messages[1]->id);
    }

    public function testEachMessagesMultiplePages(): void
    {
        $client = $this->createMockClient([
            // First page
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => 'msg_1', 'to' => '+15551234567', 'text' => 'Message 1', 'status' => 'delivered', 'credits_used' => 1, 'created_at' => '2024-01-01T12:00:00Z', 'updated_at' => '2024-01-01T12:00:00Z'],
                    ['id' => 'msg_2', 'to' => '+15559876543', 'text' => 'Message 2', 'status' => 'sent', 'credits_used' => 1, 'created_at' => '2024-01-01T12:01:00Z', 'updated_at' => '2024-01-01T12:01:00Z'],
                ],
                'pagination' => ['total' => 3, 'limit' => 2, 'offset' => 0, 'has_more' => true],
            ])),
            // Second page
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => 'msg_3', 'to' => '+15551111111', 'text' => 'Message 3', 'status' => 'delivered', 'credits_used' => 1, 'created_at' => '2024-01-01T12:02:00Z', 'updated_at' => '2024-01-01T12:02:00Z'],
                ],
                'pagination' => ['total' => 3, 'limit' => 2, 'offset' => 2, 'has_more' => false],
            ])),
        ]);

        $messages = iterator_to_array($client->messages()->each(['batchSize' => 2]));

        $this->assertCount(3, $messages);
        $this->assertSame('msg_1', $messages[0]->id);
        $this->assertSame('msg_2', $messages[1]->id);
        $this->assertSame('msg_3', $messages[2]->id);
    }

    public function testEachMessagesWithStatusFilter(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => 'msg_1', 'to' => '+15551234567', 'text' => 'Message 1', 'status' => 'delivered', 'credits_used' => 1, 'created_at' => '2024-01-01T12:00:00Z', 'updated_at' => '2024-01-01T12:00:00Z'],
                ],
                'pagination' => ['total' => 1, 'limit' => 100, 'offset' => 0, 'has_more' => false],
            ])),
        ]);

        $messages = iterator_to_array($client->messages()->each(['status' => 'delivered']));

        $this->assertCount(1, $messages);
        $this->assertSame('delivered', $messages[0]->status);
    }

    public function testEachMessagesEmpty(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [],
                'pagination' => ['total' => 0, 'limit' => 100, 'offset' => 0, 'has_more' => false],
            ])),
        ]);

        $messages = iterator_to_array($client->messages()->each());

        $this->assertCount(0, $messages);
    }
}
