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
                    'hasMore' => false,
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
                    'hasMore' => true,
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
                    'hasMore' => false,
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
                    'hasMore' => false,
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
                    'hasMore' => false,
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
                'pagination' => ['total' => 2, 'limit' => 100, 'offset' => 0, 'hasMore' => false],
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
                'pagination' => ['total' => 3, 'limit' => 2, 'offset' => 0, 'hasMore' => true],
            ])),
            // Second page
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => 'msg_3', 'to' => '+15551111111', 'text' => 'Message 3', 'status' => 'delivered', 'credits_used' => 1, 'created_at' => '2024-01-01T12:02:00Z', 'updated_at' => '2024-01-01T12:02:00Z'],
                ],
                'pagination' => ['total' => 3, 'limit' => 2, 'offset' => 2, 'hasMore' => false],
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
                'pagination' => ['total' => 1, 'limit' => 100, 'offset' => 0, 'hasMore' => false],
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
                'pagination' => ['total' => 0, 'limit' => 100, 'offset' => 0, 'hasMore' => false],
            ])),
        ]);

        $messages = iterator_to_array($client->messages()->each());

        $this->assertCount(0, $messages);
    }

    // ==================== pagination as GET /api/v1/messages sends it ====================

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface, response: ?Response}> */
    private array $history = [];

    private function createRecordingClient(array $responses): Sendly
    {
        $this->history = [];
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(\GuzzleHttp\Middleware::history($this->history));

        $client = new Sendly('test_api_key');
        $property = (new ReflectionClass($client))->getProperty('httpClient');
        $property->setValue($client, new Client(['handler' => $handlerStack]));

        return $client;
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(int $index): array
    {
        parse_str($this->history[$index]['request']->getUri()->getQuery(), $query);
        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function wireMessage(string $id): array
    {
        return [
            'id' => $id,
            'to' => '+15551234567',
            'from' => '+18885550100',
            'text' => 'Hello',
            'status' => 'delivered',
            'direction' => 'outbound',
            'error' => null,
            'errorCode' => null,
            'retryCount' => 0,
            'segments' => 1,
            'creditsUsed' => 2,
            'isSandbox' => false,
            'createdAt' => '2026-09-01T10:00:00.000Z',
            'deliveredAt' => '2026-09-01T10:00:03.000Z',
            'message_format' => 'sms',
            'messageFormat' => 'sms',
        ];
    }

    /**
     * @param array<int, string> $ids
     * @return array<string, mixed>
     */
    private function wirePage(array $ids, int $total, int $limit, int $offset): array
    {
        return [
            'data' => array_map(fn(string $id) => $this->wireMessage($id), $ids),
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'page' => intdiv($offset, $limit) + 1,
                'totalPages' => (int) ceil($total / $limit),
                'hasMore' => $offset + count($ids) < $total,
            ],
            'count' => count($ids),
        ];
    }

    public function testListReadsHasMoreAsTheApiSendsIt(): void
    {
        $client = $this->createRecordingClient([
            new Response(200, [], json_encode($this->wirePage(['msg_1', 'msg_2'], 3, 2, 0))),
        ]);

        $list = $client->messages()->list(['limit' => 2]);

        $this->assertSame(3, $list->total);
        $this->assertTrue($list->hasMore);
    }

    public function testListStillReadsSnakeCaseHasMore(): void
    {
        $list = new MessageList([
            'data' => [$this->wireMessage('msg_1')],
            'pagination' => ['total' => 5, 'limit' => 1, 'offset' => 0, 'has_more' => true],
        ]);

        $this->assertTrue($list->hasMore);
    }

    public function testEachWalksEveryPageTheApiReports(): void
    {
        $client = $this->createRecordingClient([
            new Response(200, [], json_encode($this->wirePage(['msg_1', 'msg_2'], 3, 2, 0))),
            new Response(200, [], json_encode($this->wirePage(['msg_3'], 3, 2, 2))),
        ]);

        $messages = iterator_to_array($client->messages()->each(['batchSize' => 2]), false);

        $this->assertSame(['msg_1', 'msg_2', 'msg_3'], array_map(fn(Message $m) => $m->id, $messages));
        $this->assertCount(2, $this->history);
        $this->assertSame('0', $this->queryOf(0)['offset']);
        $this->assertSame('2', $this->queryOf(1)['offset']);
    }

    public function testEachWithABatchSizeOverTheApiLimitSkipsNothing(): void
    {
        $first = array_map(fn(int $i) => 'msg_' . $i, range(1, 100));
        $second = array_map(fn(int $i) => 'msg_' . $i, range(101, 150));

        $client = $this->createRecordingClient([
            new Response(200, [], json_encode($this->wirePage($first, 150, 100, 0))),
            new Response(200, [], json_encode($this->wirePage($second, 150, 100, 100))),
        ]);

        $messages = iterator_to_array($client->messages()->each(['batchSize' => 200]), false);
        $ids = array_map(fn(Message $m) => $m->id, $messages);

        $this->assertCount(150, array_unique($ids));
        $this->assertCount(2, $this->history);
        $this->assertSame('100', $this->queryOf(0)['limit']);
        $this->assertSame('0', $this->queryOf(0)['offset']);
        $this->assertSame('100', $this->queryOf(1)['offset']);
    }

    public function testEachStopsAtAnEmptyPageEvenWhenHasMoreSaysMore(): void
    {
        $first = array_map(fn(int $i) => 'msg_' . $i, range(1, 100));

        $client = $this->createRecordingClient([
            new Response(200, [], json_encode($this->wirePage($first, 150, 100, 0))),
            new Response(200, [], json_encode($this->wirePage([], 150, 100, 100))),
        ]);

        $messages = iterator_to_array($client->messages()->each(), false);

        $this->assertCount(100, $messages);
        $this->assertCount(2, $this->history);
    }

    // ==================== Message fields as the API sends them ====================

    public function testMessageReadsTheFailureTextFromError(): void
    {
        $message = new Message([
            'id' => 'msg_1',
            'status' => 'failed',
            'error' => 'Carrier rejected the message',
            'errorCode' => 'carrier_rejected',
        ]);

        $this->assertSame('Carrier rejected the message', $message->errorMessage);
        $this->assertSame('carrier_rejected', $message->errorCode);
        $this->assertSame('Carrier rejected the message', $message->toArray()['error_message']);
    }

    public function testGetReadsTheFailureTextOfAFailedMessage(): void
    {
        $client = $this->createRecordingClient([
            new Response(200, [], json_encode(array_merge($this->wireMessage('msg_1'), [
                'status' => 'failed',
                'error' => 'The number is not reachable',
                'errorCode' => 'E001',
                'deliveredAt' => null,
            ]))),
        ]);

        $message = $client->messages()->get('msg_1');

        $this->assertSame('The number is not reachable', $message->errorMessage);
        $this->assertSame('E001', $message->errorCode);
    }

    public function testMessageWithoutAnErrorHasNoErrorMessage(): void
    {
        $this->assertNull((new Message($this->wireMessage('msg_1')))->errorMessage);
        $this->assertSame('legacy', (new Message(['id' => 'm', 'error_message' => 'legacy']))->errorMessage);
    }

    public function testUpdatedAtIsTheCreationTimeWhenNothingLaterIsKnown(): void
    {
        $message = new Message(['id' => 'm', 'createdAt' => '2026-01-01T00:00:00Z']);

        $this->assertSame('2026-01-01T00:00:00+00:00', $message->updatedAt->format(DATE_ATOM));
        $this->assertSame($message->createdAt->format(DATE_ATOM), $message->updatedAt->format(DATE_ATOM));
    }

    public function testUpdatedAtIsTheDeliveryTimeOnceDelivered(): void
    {
        $message = new Message([
            'id' => 'm',
            'createdAt' => '2026-01-01T00:00:00Z',
            'deliveredAt' => '2026-01-01T00:00:05Z',
        ]);

        $this->assertSame('2026-01-01T00:00:05+00:00', $message->updatedAt->format(DATE_ATOM));
    }

    public function testUpdatedAtStillReadsAnUpdateTimeWhenOneIsSent(): void
    {
        $message = new Message([
            'id' => 'm',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-02T00:00:00Z',
        ]);

        $this->assertSame('2026-01-02T00:00:00+00:00', $message->updatedAt->format(DATE_ATOM));
    }

    /**
     * @return array<string, mixed>
     */
    private function wireSendResponse(array $overrides = []): array
    {
        return array_merge([
            'id' => 'msg_sent',
            'to' => '+15551234567',
            'from' => '+18885550100',
            'text' => 'Hello',
            'status' => 'delivered',
            'direction' => 'outbound',
            'error' => null,
            'segments' => 1,
            'creditsUsed' => 0,
            'createdAt' => '2026-09-25T10:00:00.000Z',
            'metadata' => [],
        ], $overrides);
    }

    public function testATestKeySendReportsItWasSimulated(): void
    {
        $client = $this->createRecordingClient([
            new Response(201, [], json_encode($this->wireSendResponse(['simulated' => true]))),
        ]);

        $message = $client->messages()->send('+15551234567', 'Hello');

        $this->assertTrue($message->simulated);
        $this->assertTrue($message->isSimulated());
        $this->assertNull($message->simulatedReason);
        $this->assertTrue($message->toArray()['simulated']);
    }

    public function testALiveSendThatFellBackReportsWhy(): void
    {
        $reason = "This account isn't set up to send to this destination yet, so the message was simulated, not delivered to a handset.";
        $client = $this->createRecordingClient([
            new Response(201, [], json_encode($this->wireSendResponse([
                'senderType' => 'number_pool',
                'simulated' => true,
                'simulatedReason' => $reason,
                'actionUrl' => '/verify',
            ]))),
        ]);

        $message = $client->messages()->send('+15551234567', 'Hello');

        $this->assertTrue($message->isSimulated());
        $this->assertSame($reason, $message->simulatedReason);
        $this->assertSame($reason, $message->toArray()['simulated_reason']);
    }

    public function testARealSendIsNotSimulated(): void
    {
        $client = $this->createRecordingClient([
            new Response(201, [], json_encode($this->wireSendResponse([
                'status' => 'queued',
                'creditsUsed' => 2,
                'senderType' => 'number_pool',
            ]))),
        ]);

        $message = $client->messages()->send('+15551234567', 'Hello');

        $this->assertFalse($message->simulated);
        $this->assertFalse($message->isSimulated());
    }

    public function testMessageReadsFormatMediaAndBatchInSnakeCase(): void
    {
        $message = new Message([
            'id' => 'm',
            'message_format' => 'mms',
            'media_urls' => ['https://x/a.jpg'],
            'batch_id' => 'batch_1',
        ]);

        $this->assertSame('mms', $message->messageFormat);
        $this->assertSame(['https://x/a.jpg'], $message->mediaUrls);
        $this->assertSame('batch_1', $message->batchId);
        $this->assertSame('mms', $message->toArray()['message_format']);
        $this->assertSame(['https://x/a.jpg'], $message->toArray()['media_urls']);
        $this->assertSame('batch_1', $message->toArray()['batch_id']);
    }

    public function testMessageReadsFormatMediaAndBatchInCamelCase(): void
    {
        $message = new Message([
            'id' => 'm',
            'messageFormat' => 'mms',
            'mediaUrls' => ['https://x/a.jpg'],
            'batchId' => 'batch_1',
        ]);

        $this->assertSame('mms', $message->messageFormat);
        $this->assertSame(['https://x/a.jpg'], $message->mediaUrls);
        $this->assertSame('batch_1', $message->batchId);
    }

    public function testAnSmsHasNoMediaOrBatch(): void
    {
        $message = new Message(['id' => 'm', 'text' => 'Hi']);

        $this->assertSame('sms', $message->messageFormat);
        $this->assertNull($message->mediaUrls);
        $this->assertNull($message->batchId);
    }

    public function testListedMmsKeepsItsMediaAndBatch(): void
    {
        $item = array_merge($this->wireMessage('msg_1'), [
            'message_format' => 'mms',
            'messageFormat' => 'mms',
            'media_urls' => ['https://cdn.example.com/a.jpg'],
            'mediaUrls' => ['https://cdn.example.com/a.jpg'],
            'batch_id' => 'batch_9',
            'batchId' => 'batch_9',
        ]);
        $client = $this->createRecordingClient([
            new Response(200, [], json_encode([
                'data' => [$item],
                'pagination' => ['total' => 1, 'limit' => 20, 'offset' => 0, 'page' => 1, 'totalPages' => 1, 'hasMore' => false],
                'count' => 1,
            ])),
        ]);

        $message = $client->messages()->list()->first();

        $this->assertSame('mms', $message->messageFormat);
        $this->assertSame(['https://cdn.example.com/a.jpg'], $message->mediaUrls);
        $this->assertSame('batch_9', $message->batchId);
    }

    public function testListedWhatsAppAndRcsMessagesKeepTheirFormatAndMedia(): void
    {
        $whatsapp = array_merge($this->wireMessage('msg_wa'), [
            'text' => 'Your order has shipped',
            'creditsUsed' => 1,
            'message_format' => 'whatsapp',
            'messageFormat' => 'whatsapp',
            'media_urls' => ['https://cdn.example.com/parcel.jpg'],
            'mediaUrls' => ['https://cdn.example.com/parcel.jpg'],
        ]);
        $rcs = array_merge($this->wireMessage('msg_rcs'), [
            'from' => 'acme_agent',
            'text' => 'Spring sale',
            'message_format' => 'rcs',
            'messageFormat' => 'rcs',
            'media_urls' => ['https://cdn.example.com/card.png'],
            'mediaUrls' => ['https://cdn.example.com/card.png'],
        ]);
        $client = $this->createRecordingClient([
            new Response(200, [], json_encode([
                'data' => [$whatsapp, $rcs],
                'pagination' => ['total' => 2, 'limit' => 20, 'offset' => 0, 'page' => 1, 'totalPages' => 1, 'hasMore' => false],
                'count' => 2,
            ])),
        ]);

        [$first, $second] = $client->messages()->list()->all();

        $this->assertSame('whatsapp', $first->messageFormat);
        $this->assertSame(['https://cdn.example.com/parcel.jpg'], $first->mediaUrls);
        $this->assertSame('rcs', $second->messageFormat);
        $this->assertSame(['https://cdn.example.com/card.png'], $second->mediaUrls);

        $formatDoc = (string) (new \ReflectionProperty(Message::class, 'messageFormat'))->getDocComment();
        $mediaDoc = (string) (new \ReflectionProperty(Message::class, 'mediaUrls'))->getDocComment();
        $this->assertStringContainsString("'whatsapp'", $formatDoc);
        $this->assertStringContainsString("'rcs'", $formatDoc);
        $this->assertStringContainsString('WhatsApp', $mediaDoc);
        $this->assertStringContainsString('RCS', $mediaDoc);
    }

    // ==================== the 1600-character limit ====================

    public function testSendAcceptsMultibyteTextWithinTheCharacterLimit(): void
    {
        $text = str_repeat('é', 1000);
        $client = $this->createRecordingClient([
            new Response(201, [], json_encode($this->wireSendResponse(['text' => $text]))),
        ]);

        $client->messages()->send('+15551234567', $text);

        $this->assertCount(1, $this->history);
        $this->assertSame($text, json_decode((string) $this->history[0]['request']->getBody(), true)['text']);
    }

    public function testSendBatchAcceptsMultibyteTextWithinTheCharacterLimit(): void
    {
        $text = str_repeat('日', 1600);
        $client = $this->createRecordingClient([
            new Response(200, [], json_encode(['batchId' => 'batch_1', 'status' => 'processing', 'total' => 1])),
        ]);

        $client->messages()->sendBatch([['to' => '+15551234567', 'text' => $text]]);

        $this->assertCount(1, $this->history);
    }

    public function testSendStillRejectsTextOverTheCharacterLimit(): void
    {
        $client = $this->createRecordingClient([]);

        try {
            $client->messages()->send('+15551234567', str_repeat('é', 1601));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('Message text exceeds maximum length (1600 characters)', $e->getMessage());
        }

        $this->assertCount(0, $this->history);
    }

    // ==================== v1.0.6: array-style send + public property access ====================

    public function testSendAcceptsOptionsArray(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'message' => [
                    'id' => 'msg_array',
                    'to' => '+15551234567',
                    'text' => 'array style',
                    'status' => 'queued',
                    'credits_used' => 1,
                    'created_at' => '2024-01-01T12:00:00Z',
                    'updated_at' => '2024-01-01T12:00:00Z',
                ],
            ])),
        ]);

        $message = $client->messages()->send([
            'to' => '+15551234567',
            'text' => 'array style',
            'messageType' => 'transactional',
        ]);

        $this->assertSame('msg_array', $message->id);
        $this->assertSame('array style', $message->text);
    }

    public function testSendArrayStyleRequiresText(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $client->messages()->send(['to' => '+15551234567']);
    }

    public function testResourcePropertiesArePublicallyAccessible(): void
    {
        $client = new Sendly('test_api_key');
        // Public property access — matches our Node/Python/Ruby SDK
        // idiom and the code samples shipped in our docs.
        $this->assertInstanceOf(\Sendly\Resources\Messages::class, $client->messages);
        $this->assertInstanceOf(\Sendly\Resources\Webhooks::class, $client->webhooks);
        $this->assertInstanceOf(\Sendly\Resources\Account::class, $client->account);
        $this->assertInstanceOf(\Sendly\Resources\Verify::class, $client->verify);
        $this->assertInstanceOf(\Sendly\Resources\Campaigns::class, $client->campaigns);
        $this->assertInstanceOf(\Sendly\Resources\Contacts::class, $client->contacts);
        $this->assertInstanceOf(\Sendly\Resources\Enterprise::class, $client->enterprise);
        $this->assertInstanceOf(\Sendly\Resources\Conversations::class, $client->conversations);
        $this->assertInstanceOf(\Sendly\Resources\Labels::class, $client->labels);
        $this->assertInstanceOf(\Sendly\Resources\Drafts::class, $client->drafts);
        $this->assertInstanceOf(\Sendly\Resources\Rules::class, $client->rules);
    }

    public function testMethodAccessorsStillWorkForBackwardCompat(): void
    {
        $client = new Sendly('test_api_key');
        // Legacy method-style access — must keep working so v1.0.5
        // consumers don't break on upgrade.
        $this->assertInstanceOf(\Sendly\Resources\Messages::class, $client->messages());
        $this->assertSame($client->messages, $client->messages());
    }
}
