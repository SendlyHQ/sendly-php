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
 * Tests for scheduled messages: schedule(), listScheduled(), getScheduled(), cancelScheduled()
 */
class MessagesScheduleTest extends TestCase
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

    // ==================== schedule() Tests ====================

    public function testScheduleMessageSuccess(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'sched_123',
                'to' => '+15551234567',
                'text' => 'Scheduled message',
                'scheduled_at' => '2024-12-25T10:00:00Z',
                'status' => 'scheduled',
                'credits_reserved' => 1,
                'created_at' => '2024-01-01T12:00:00Z',
            ])),
        ]);

        $result = $client->messages()->schedule(
            '+15551234567',
            'Scheduled message',
            '2024-12-25T10:00:00Z'
        );

        $this->assertIsArray($result);
        $this->assertSame('sched_123', $result['id']);
        $this->assertSame('+15551234567', $result['to']);
        $this->assertSame('Scheduled message', $result['text']);
        $this->assertSame('2024-12-25T10:00:00Z', $result['scheduled_at']);
        $this->assertSame('scheduled', $result['status']);
    }

    public function testScheduleMessageWithFrom(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'sched_123',
                'to' => '+15551234567',
                'from' => 'MyBrand',
                'text' => 'Scheduled message',
                'scheduled_at' => '2024-12-25T10:00:00Z',
                'status' => 'scheduled',
                'credits_reserved' => 1,
                'created_at' => '2024-01-01T12:00:00Z',
            ])),
        ]);

        $result = $client->messages()->schedule(
            '+15551234567',
            'Scheduled message',
            '2024-12-25T10:00:00Z',
            'MyBrand'
        );

        $this->assertSame('MyBrand', $result['from']);
    }

    public function testScheduleMessageWithInvalidPhone(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');

        $client->messages()->schedule('1234567890', 'Test', '2024-12-25T10:00:00Z');
    }

    public function testScheduleMessageWithEmptyText(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Message text is required');

        $client->messages()->schedule('+15551234567', '', '2024-12-25T10:00:00Z');
    }

    public function testScheduleMessageWithTooLongText(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Message text exceeds maximum length');

        $longText = str_repeat('a', 1601);
        $client->messages()->schedule('+15551234567', $longText, '2024-12-25T10:00:00Z');
    }

    public function testScheduleMessageWithEmptyScheduledTime(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Scheduled time is required');

        $client->messages()->schedule('+15551234567', 'Test message', '');
    }

    public function testScheduleMessageAuthenticationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unauthorized',
                new Request('POST', '/messages/schedule'),
                new Response(401, [], json_encode(['error' => 'Invalid API key']))
            ),
        ]);

        $this->expectException(AuthenticationException::class);

        $client->messages()->schedule('+15551234567', 'Test', '2024-12-25T10:00:00Z');
    }

    public function testScheduleMessageInsufficientCredits(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Payment Required',
                new Request('POST', '/messages/schedule'),
                new Response(402, [], json_encode(['error' => 'Insufficient credits']))
            ),
        ]);

        $this->expectException(InsufficientCreditsException::class);

        $client->messages()->schedule('+15551234567', 'Test', '2024-12-25T10:00:00Z');
    }

    public function testScheduleMessageRateLimitError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Too Many Requests',
                new Request('POST', '/messages/schedule'),
                new Response(429, ['Retry-After' => '30'], json_encode(['error' => 'Rate limit exceeded']))
            ),
        ]);

        try {
            $client->messages()->schedule('+15551234567', 'Test', '2024-12-25T10:00:00Z');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(30, $e->getRetryAfter());
        }
    }

    public function testScheduleMessageServerError(): void
    {
        $client = $this->createMockClient([
            new RequestException('Server Error', new Request('POST', '/messages/schedule'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('POST', '/messages/schedule'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('POST', '/messages/schedule'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('POST', '/messages/schedule'), new Response(500, [], json_encode(['error' => 'Server error']))),
        ]);

        $this->expectException(SendlyException::class);

        $client->messages()->schedule('+15551234567', 'Test', '2024-12-25T10:00:00Z');
    }

    public function testScheduleMessageNetworkError(): void
    {
        $client = $this->createMockClient([
            new ConnectException('Connection refused', new Request('POST', '/messages/schedule')),
            new ConnectException('Connection refused', new Request('POST', '/messages/schedule')),
            new ConnectException('Connection refused', new Request('POST', '/messages/schedule')),
            new ConnectException('Connection refused', new Request('POST', '/messages/schedule')),
        ]);

        $this->expectException(NetworkException::class);

        $client->messages()->schedule('+15551234567', 'Test', '2024-12-25T10:00:00Z');
    }

    // ==================== listScheduled() Tests ====================

    public function testListScheduledSuccess(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    [
                        'id' => 'sched_1',
                        'to' => '+15551234567',
                        'text' => 'Message 1',
                        'scheduled_at' => '2024-12-25T10:00:00Z',
                        'status' => 'scheduled',
                        'credits_reserved' => 1,
                    ],
                    [
                        'id' => 'sched_2',
                        'to' => '+15559876543',
                        'text' => 'Message 2',
                        'scheduled_at' => '2024-12-26T10:00:00Z',
                        'status' => 'scheduled',
                        'credits_reserved' => 1,
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

        $result = $client->messages()->listScheduled();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);
        $this->assertSame('sched_1', $result['data'][0]['id']);
        $this->assertSame('sched_2', $result['data'][1]['id']);
    }

    public function testListScheduledWithPagination(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => 'sched_1', 'to' => '+15551234567', 'text' => 'Message 1', 'scheduled_at' => '2024-12-25T10:00:00Z', 'status' => 'scheduled', 'credits_reserved' => 1],
                ],
                'pagination' => [
                    'total' => 50,
                    'limit' => 10,
                    'offset' => 20,
                    'has_more' => true,
                ],
            ])),
        ]);

        $result = $client->messages()->listScheduled(['limit' => 10, 'offset' => 20]);

        $this->assertSame(10, $result['pagination']['limit']);
        $this->assertSame(20, $result['pagination']['offset']);
        $this->assertTrue($result['pagination']['has_more']);
    }

    public function testListScheduledWithStatusFilter(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    ['id' => 'sched_1', 'to' => '+15551234567', 'text' => 'Message 1', 'scheduled_at' => '2024-12-25T10:00:00Z', 'status' => 'cancelled', 'credits_reserved' => 0],
                ],
                'pagination' => ['total' => 1, 'limit' => 20, 'offset' => 0, 'has_more' => false],
            ])),
        ]);

        $result = $client->messages()->listScheduled(['status' => 'cancelled']);

        $this->assertSame('cancelled', $result['data'][0]['status']);
    }

    public function testListScheduledAuthenticationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unauthorized',
                new Request('GET', '/messages/scheduled'),
                new Response(401, [], json_encode(['error' => 'Invalid API key']))
            ),
        ]);

        $this->expectException(AuthenticationException::class);

        $client->messages()->listScheduled();
    }

    public function testListScheduledNotFoundError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Not Found',
                new Request('GET', '/messages/scheduled'),
                new Response(404, [], json_encode(['error' => 'Endpoint not found']))
            ),
        ]);

        $this->expectException(NotFoundException::class);

        $client->messages()->listScheduled();
    }

    // ==================== getScheduled() Tests ====================

    public function testGetScheduledSuccess(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'sched_123',
                'to' => '+15551234567',
                'text' => 'Scheduled message',
                'scheduled_at' => '2024-12-25T10:00:00Z',
                'status' => 'scheduled',
                'credits_reserved' => 1,
                'created_at' => '2024-01-01T12:00:00Z',
            ])),
        ]);

        $result = $client->messages()->getScheduled('sched_123');

        $this->assertIsArray($result);
        $this->assertSame('sched_123', $result['id']);
        $this->assertSame('scheduled', $result['status']);
    }

    public function testGetScheduledWithEmptyId(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Scheduled message ID is required');

        $client->messages()->getScheduled('');
    }

    public function testGetScheduledNotFound(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Not Found',
                new Request('GET', '/messages/scheduled/invalid_id'),
                new Response(404, [], json_encode(['error' => 'Scheduled message not found']))
            ),
        ]);

        $this->expectException(NotFoundException::class);

        $client->messages()->getScheduled('invalid_id');
    }

    public function testGetScheduledAuthenticationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unauthorized',
                new Request('GET', '/messages/scheduled/sched_123'),
                new Response(401, [], json_encode(['error' => 'Invalid API key']))
            ),
        ]);

        $this->expectException(AuthenticationException::class);

        $client->messages()->getScheduled('sched_123');
    }

    // ==================== cancelScheduled() Tests ====================

    public function testCancelScheduledSuccess(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'sched_123',
                'status' => 'cancelled',
                'credits_refunded' => 1,
                'cancelled_at' => '2024-01-01T12:30:00Z',
            ])),
        ]);

        $result = $client->messages()->cancelScheduled('sched_123');

        $this->assertIsArray($result);
        $this->assertSame('sched_123', $result['id']);
        $this->assertSame('cancelled', $result['status']);
        $this->assertSame(1, $result['credits_refunded']);
    }

    public function testCancelScheduledWithEmptyId(): void
    {
        $client = new Sendly('test_api_key');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Scheduled message ID is required');

        $client->messages()->cancelScheduled('');
    }

    public function testCancelScheduledNotFound(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Not Found',
                new Request('DELETE', '/messages/scheduled/invalid_id'),
                new Response(404, [], json_encode(['error' => 'Scheduled message not found']))
            ),
        ]);

        $this->expectException(NotFoundException::class);

        $client->messages()->cancelScheduled('invalid_id');
    }

    public function testCancelScheduledAuthenticationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unauthorized',
                new Request('DELETE', '/messages/scheduled/sched_123'),
                new Response(401, [], json_encode(['error' => 'Invalid API key']))
            ),
        ]);

        $this->expectException(AuthenticationException::class);

        $client->messages()->cancelScheduled('sched_123');
    }

    public function testCancelScheduledInsufficientCreditsError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Payment Required',
                new Request('DELETE', '/messages/scheduled/sched_123'),
                new Response(402, [], json_encode(['error' => 'Cannot cancel - already sent']))
            ),
        ]);

        $this->expectException(InsufficientCreditsException::class);

        $client->messages()->cancelScheduled('sched_123');
    }

    public function testCancelScheduledRateLimitError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Too Many Requests',
                new Request('DELETE', '/messages/scheduled/sched_123'),
                new Response(429, ['Retry-After' => '15'], json_encode(['error' => 'Rate limit exceeded']))
            ),
        ]);

        try {
            $client->messages()->cancelScheduled('sched_123');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(15, $e->getRetryAfter());
        }
    }

    public function testCancelScheduledServerError(): void
    {
        $client = $this->createMockClient([
            new RequestException('Server Error', new Request('DELETE', '/messages/scheduled/sched_123'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('DELETE', '/messages/scheduled/sched_123'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('DELETE', '/messages/scheduled/sched_123'), new Response(500, [], json_encode(['error' => 'Server error']))),
            new RequestException('Server Error', new Request('DELETE', '/messages/scheduled/sched_123'), new Response(500, [], json_encode(['error' => 'Server error']))),
        ]);

        $this->expectException(SendlyException::class);

        $client->messages()->cancelScheduled('sched_123');
    }

    public function testCancelScheduledNetworkError(): void
    {
        $client = $this->createMockClient([
            new ConnectException('Connection refused', new Request('DELETE', '/messages/scheduled/sched_123')),
            new ConnectException('Connection refused', new Request('DELETE', '/messages/scheduled/sched_123')),
            new ConnectException('Connection refused', new Request('DELETE', '/messages/scheduled/sched_123')),
            new ConnectException('Connection refused', new Request('DELETE', '/messages/scheduled/sched_123')),
        ]);

        $this->expectException(NetworkException::class);

        $client->messages()->cancelScheduled('sched_123');
    }
}
