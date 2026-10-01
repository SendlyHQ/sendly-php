<?php

declare(strict_types=1);

namespace Sendly\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use Sendly\Exceptions\ValidationException;
use Sendly\Sendly;
use Sendly\WebhookTestResult;

/**
 * Tests for the Webhooks resource ($client->webhooks)
 */
class WebhooksResourceTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface, response: ?Response}> */
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
        $property->setValue($client, $httpClient);

        return $client;
    }

    // ==================== listDeliveries() ====================

    public function testListDeliveriesReadsTheDeliveriesEnvelope(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'deliveries' => [
                    [
                        'id' => 'whd_1',
                        'webhook_id' => 'whk_1',
                        'event_id' => 'evt_0123456789abcdef0123456789abcdef01234567',
                        'event_type' => 'message.delivered',
                        'status' => 'delivered',
                        'success' => true,
                        'response_status_code' => 200,
                        'http_status' => 200,
                        'response_time' => 87,
                        'response_time_ms' => 87,
                        'response_body' => 'ok',
                        'error_message' => null,
                        'error_code' => null,
                        'attempt_number' => 1,
                        'max_attempts' => 6,
                        'next_retry_at' => null,
                        'created_at' => '2026-09-25T10:00:00.000Z',
                        'delivered_at' => '2026-09-25T10:00:00.087Z',
                    ],
                ],
                'pagination' => ['limit' => 20, 'offset' => 0],
            ])),
        ]);

        $deliveries = $client->webhooks->listDeliveries('whk_1');

        $this->assertCount(1, $deliveries);
        $this->assertSame('message.delivered', $deliveries[0]->eventType);
        $this->assertSame(1, $deliveries[0]->attemptNumber);
        $this->assertSame(200, $deliveries[0]->httpStatus);
        $this->assertSame(87, $deliveries[0]->responseTimeMs);
        $this->assertTrue($deliveries[0]->success);
        $this->assertSame('/api/v1/webhooks/whk_1/deliveries', $this->history[0]['request']->getUri()->getPath());
    }

    // ==================== backfill() ====================

    public function testBackfillDocsSayToDedupeOnTheEventId(): void
    {
        $doc = (string) (new \ReflectionMethod(\Sendly\Resources\Webhooks::class, 'backfill'))->getDocComment();

        $this->assertStringNotContainsString('fresh IDs', $doc);
        $this->assertStringNotContainsString('dedupe by event.data.object.id', $doc);
        $this->assertStringContainsString('dedupe on event.id', $doc);
    }

    // ==================== test() ====================

    public function testTestReadsTheDeliveryTheApiNestsTheResultUnder(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'success' => true,
                'message' => 'Test webhook delivered successfully in 123ms',
                'delivery' => [
                    'id' => 'whd_1',
                    'delivery_id' => 'whd_1',
                    'webhook_url' => 'https://example.com/hooks/sendly',
                    'event_type' => 'webhook.test',
                    'status' => 'delivered',
                    'response_time' => 123,
                    'status_code' => 204,
                    'response_body' => '',
                    'delivered_at' => '2026-09-25T10:00:00.000Z',
                ],
            ])),
        ]);

        $result = $client->webhooks->test('whk_1');

        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertSame('/api/v1/webhooks/whk_1/test', $this->history[0]['request']->getUri()->getPath());
        $this->assertInstanceOf(WebhookTestResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame(204, $result->statusCode);
        $this->assertSame(123, $result->responseTimeMs);
        $this->assertNull($result->error);
        $this->assertSame('whd_1', $result->deliveryId);
        $this->assertSame('Test webhook delivered successfully in 123ms', $result->message);
    }

    public function testTestStillReadsTopLevelFields(): void
    {
        $result = new WebhookTestResult([
            'success' => false,
            'status_code' => 500,
            'response_time_ms' => 80,
            'error' => 'HTTP 500',
        ]);

        $this->assertFalse($result->success);
        $this->assertSame(500, $result->statusCode);
        $this->assertSame(80, $result->responseTimeMs);
        $this->assertSame('HTTP 500', $result->error);
        $this->assertNull($result->deliveryId);
    }

    public function testAFailedTestThrowsWithTheApisBody(): void
    {
        $client = $this->createMockClient([
            new Response(400, [], json_encode([
                'success' => false,
                'message' => 'Test webhook failed: HTTP 500',
            ])),
        ]);

        try {
            $client->webhooks->test('whk_1');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('Test webhook failed: HTTP 500', $e->getMessage());
            $this->assertSame(400, $e->getCode());
            $this->assertFalse($e->getResponseBody()['success']);
        }

        $this->assertCount(1, $this->history);
    }

    public function testTestOfAMissingWebhookThrowsAValidationException(): void
    {
        $client = $this->createMockClient([
            new Response(400, [], json_encode([
                'success' => false,
                'message' => 'Webhook not found or access denied',
            ])),
        ]);

        try {
            $client->webhooks->test('whk_missing');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('Webhook not found or access denied', $e->getMessage());
            $this->assertSame(400, $e->getCode());
        }

        $doc = (string) (new \ReflectionMethod(\Sendly\Resources\Webhooks::class, 'test'))->getDocComment();
        $this->assertStringContainsString('the webhook is not found', $doc);
    }
}
