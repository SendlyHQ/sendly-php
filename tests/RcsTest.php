<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Sendly\Sendly;
use Sendly\Resources\Rcs;
use Sendly\Resources\RcsAgents;
use Sendly\Exceptions\SendlyException;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\NotFoundException;
use ReflectionClass;

/**
 * Tests for RCS resource: agents->list(), capability(), and
 * messages()->send(['channel' => 'rcs', ...])
 */
class RcsTest extends TestCase
{
    private function createMockClient(array $responses, array $options = []): Sendly
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new Sendly('test_api_key', $options);

        // Use reflection to inject the mock HTTP client
        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($client, $httpClient);

        return $client;
    }

    private function createCapturingClient(array $responses, ?string &$capturedBody, ?string &$capturedPath = null, ?string &$capturedQuery = null, ?string &$capturedMethod = null): Sendly
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(\GuzzleHttp\Middleware::mapRequest(function ($request) use (&$capturedBody, &$capturedPath, &$capturedQuery, &$capturedMethod) {
            $capturedBody = (string) $request->getBody();
            $capturedPath = $request->getUri()->getPath();
            $capturedQuery = $request->getUri()->getQuery();
            $capturedMethod = $request->getMethod();
            return $request;
        }));
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new Sendly('test_api_key');
        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($client, $httpClient);

        return $client;
    }

    private function agent(array $overrides = []): array
    {
        return array_merge([
            'id' => 'rca_1',
            'name' => 'Acme Coffee',
            'status' => 'approved',
            'useCase' => 'PROMOTION',
            'sendable' => true,
            'createdAt' => '2026-07-30T09:00:00Z',
        ], $overrides);
    }

    private function rcsMessage(array $overrides = []): array
    {
        return array_merge([
            'id' => 'msg_rcs_1',
            'channel' => 'rcs',
            'message_format' => 'rcs',
            'to' => '+15551234567',
            'from' => 'Acme Coffee',
            'text' => 'Your order has shipped!',
            'status' => 'sent',
            'segments' => 1,
            'creditsUsed' => 2,
            'rcs' => ['kind' => 'text', 'agentId' => 'rca_1', 'agentName' => 'Acme Coffee'],
            'createdAt' => '2026-07-30T09:12:00Z',
            'metadata' => [],
        ], $overrides);
    }

    // ==================== resource registration ====================

    public function testRcsResourceIsRegistered(): void
    {
        $client = new Sendly('test_api_key');
        $this->assertInstanceOf(Rcs::class, $client->rcs);
        $this->assertInstanceOf(Rcs::class, $client->rcs());
        $this->assertSame($client->rcs, $client->rcs());
        $this->assertInstanceOf(RcsAgents::class, $client->rcs->agents);
    }

    // ==================== agents->list() ====================

    public function testAgentsList(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $client = $this->createCapturingClient([
            new Response(200, [], json_encode([
                'agents' => [
                    $this->agent(),
                    $this->agent(['id' => 'rca_2', 'name' => 'Acme Support', 'status' => 'submitted', 'useCase' => null, 'sendable' => false]),
                ],
            ])),
        ], $capturedBody, $capturedPath);

        $result = $client->rcs()->agents->list();

        $this->assertSame('/rcs/agents', $capturedPath);
        $this->assertCount(2, $result['agents']);
        $this->assertSame('rca_1', $result['agents'][0]['id']);
        $this->assertSame('Acme Coffee', $result['agents'][0]['name']);
        $this->assertSame('PROMOTION', $result['agents'][0]['useCase']);
        $this->assertTrue($result['agents'][0]['sendable']);
        $this->assertSame('submitted', $result['agents'][1]['status']);
        $this->assertNull($result['agents'][1]['useCase']);
        $this->assertFalse($result['agents'][1]['sendable']);
    }

    public function testAgentsListEmpty(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['agents' => []])),
        ]);

        $result = $client->rcs()->agents->list();

        $this->assertSame([], $result['agents']);
    }

    public function testAgentsListAbsentUntilChannelEnabled(): void
    {
        // Flag-dark accounts see the whole surface as absent (404).
        $client = $this->createMockClient([
            new RequestException(
                'Not Found',
                new Request('GET', '/rcs/agents'),
                new Response(404, [], json_encode(['error' => 'not_found']))
            ),
        ]);

        $this->expectException(NotFoundException::class);
        $client->rcs()->agents->list();
    }

    // ==================== capability() ====================

    public function testCapability(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $capturedQuery = null;
        $client = $this->createCapturingClient([
            new Response(200, [], json_encode([
                'to' => '+15551234567',
                'agentId' => 'rca_1',
                'capable' => true,
                'features' => ['RICHCARD_STANDALONE', 'ACTION_CREATE_CALENDAR_EVENT'],
            ])),
        ], $capturedBody, $capturedPath, $capturedQuery);

        $result = $client->rcs()->capability('+15551234567', 'rca_1');

        $this->assertSame('/rcs/capability', $capturedPath);
        parse_str($capturedQuery, $sentQuery);
        $this->assertSame(['to' => '+15551234567', 'agentId' => 'rca_1'], $sentQuery);
        $this->assertTrue($result['capable']);
        $this->assertSame(['RICHCARD_STANDALONE', 'ACTION_CREATE_CALENDAR_EVENT'], $result['features']);
    }

    public function testCapabilityWithoutAgentId(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $capturedQuery = null;
        $client = $this->createCapturingClient([
            new Response(200, [], json_encode([
                'to' => '+15551234567',
                'agentId' => 'rca_1',
                'capable' => false,
                'features' => [],
            ])),
        ], $capturedBody, $capturedPath, $capturedQuery);

        $result = $client->rcs()->capability('+15551234567');

        parse_str($capturedQuery, $sentQuery);
        $this->assertSame(['to' => '+15551234567'], $sentQuery);
        $this->assertFalse($result['capable']);
        $this->assertSame([], $result['features']);
    }

    public function testCapabilityValidatesPhone(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');
        $client->rcs()->capability('5551234567');
    }

    public function testCapabilityRequiresLiveKey(): void
    {
        // 403 is retried with backoff, so disable retries for this test.
        $client = $this->createMockClient([
            new RequestException(
                'Forbidden',
                new Request('GET', '/rcs/capability'),
                new Response(403, [], json_encode([
                    'error' => 'rcs_requires_live_key',
                    'message' => 'RCS capability checks require a live API key. Test keys cannot query RCS capability.',
                ]))
            ),
        ], ['maxRetries' => 0]);

        try {
            $client->rcs()->capability('+15551234567');
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame('RCS capability checks require a live API key. Test keys cannot query RCS capability.', $e->getMessage());
        }
    }

    // ==================== messages()->send(channel: rcs) ====================

    public function testSendRcsText(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode($this->rcsMessage())),
        ], $capturedBody, $capturedPath);

        $message = $client->messages()->send([
            'channel' => 'rcs',
            'to' => '+15551234567',
            'text' => 'Your order has shipped!',
        ]);

        $this->assertSame('/messages', $capturedPath);
        $sentBody = json_decode($capturedBody, true);
        $this->assertSame('rcs', $sentBody['channel']);
        $this->assertSame('+15551234567', $sentBody['to']);
        $this->assertSame('Your order has shipped!', $sentBody['text']);
        $this->assertArrayNotHasKey('card', $sentBody);
        $this->assertArrayNotHasKey('fallbackToSms', $sentBody);

        $this->assertIsArray($message);
        $this->assertSame('msg_rcs_1', $message['id']);
        $this->assertSame('rcs', $message['channel']);
        $this->assertSame('text', $message['rcs']['kind']);
        $this->assertSame('Acme Coffee', $message['rcs']['agentName']);
        $this->assertArrayNotHasKey('fellBackTo', $message);
    }

    public function testSendRcsTextWithSuggestions(): void
    {
        $capturedBody = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode($this->rcsMessage())),
        ], $capturedBody);

        $client->messages()->send([
            'channel' => 'rcs',
            'to' => '+15551234567',
            'text' => 'Your order has shipped! Want live updates?',
            'suggestions' => [
                ['reply' => ['text' => 'Yes, notify me', 'postbackData' => 'notify_yes']],
                ['action' => ['text' => 'Track order', 'postbackData' => 'track', 'url' => 'https://acme.example/orders/4821']],
            ],
            'metadata' => ['orderId' => 'ord_812'],
        ]);

        $sentBody = json_decode($capturedBody, true);
        $this->assertSame('Yes, notify me', $sentBody['suggestions'][0]['reply']['text']);
        $this->assertSame('notify_yes', $sentBody['suggestions'][0]['reply']['postbackData']);
        $this->assertSame('https://acme.example/orders/4821', $sentBody['suggestions'][1]['action']['url']);
        $this->assertSame(['orderId' => 'ord_812'], $sentBody['metadata']);
    }

    public function testSendRcsCard(): void
    {
        $capturedBody = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode($this->rcsMessage([
                'text' => null,
                'rcs' => ['kind' => 'card', 'agentId' => 'rca_1', 'agentName' => 'Acme Coffee'],
            ]))),
        ], $capturedBody);

        $message = $client->messages()->send([
            'channel' => 'rcs',
            'to' => '+15551234567',
            'agentId' => 'rca_1',
            'card' => [
                'title' => 'Spring collection',
                'description' => 'New arrivals are in - take a look.',
                'mediaUrl' => 'https://example.com/spring.jpg',
                'orientation' => 'vertical',
                'suggestions' => [
                    ['action' => ['text' => 'Shop now', 'postbackData' => 'shop', 'url' => 'https://acme.example/spring']],
                ],
            ],
        ]);

        $sentBody = json_decode($capturedBody, true);
        $this->assertSame('rca_1', $sentBody['agentId']);
        $this->assertSame('Spring collection', $sentBody['card']['title']);
        $this->assertSame('New arrivals are in - take a look.', $sentBody['card']['description']);
        $this->assertSame('https://example.com/spring.jpg', $sentBody['card']['mediaUrl']);
        $this->assertSame('vertical', $sentBody['card']['orientation']);
        $this->assertSame('shop', $sentBody['card']['suggestions'][0]['action']['postbackData']);
        $this->assertArrayNotHasKey('text', $sentBody);
        $this->assertSame('card', $message['rcs']['kind']);
    }

    public function testSendRcsFallbackResponseIsVisible(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->rcsMessage([
                'channel' => 'sms',
                'fellBackTo' => 'sms',
                'message_format' => 'sms',
                'from' => '+15559876543',
                'rcs' => ['requestedChannel' => 'rcs', 'agentId' => 'rca_1', 'suggestionsDropped' => true],
            ]))),
        ]);

        $message = $client->messages()->send([
            'channel' => 'rcs',
            'to' => '+15551234567',
            'text' => 'Your order has shipped!',
            'suggestions' => [
                ['reply' => ['text' => 'Notify me', 'postbackData' => 'notify']],
            ],
        ]);

        $this->assertSame('sms', $message['channel']);
        $this->assertSame('sms', $message['fellBackTo']);
        $this->assertSame('rcs', $message['rcs']['requestedChannel']);
        $this->assertTrue($message['rcs']['suggestionsDropped']);
    }

    public function testSendRcsFallbackDisabledIsSent(): void
    {
        $capturedBody = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode($this->rcsMessage())),
        ], $capturedBody);

        $client->messages()->send([
            'channel' => 'rcs',
            'to' => '+15551234567',
            'text' => 'RCS or nothing',
            'fallbackToSms' => false,
        ]);

        $sentBody = json_decode($capturedBody, true);
        $this->assertFalse($sentBody['fallbackToSms']);
    }

    public function testSendRcsRequiresTextOrCard(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Provide exactly one of 'text' or 'card'");
        $client->messages()->send([
            'channel' => 'rcs',
            'to' => '+15551234567',
        ]);
    }

    public function testSendRcsRejectsTextAndCard(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Provide exactly one of 'text' or 'card'");
        $client->messages()->send([
            'channel' => 'rcs',
            'to' => '+15551234567',
            'text' => 'Hello!',
            'card' => ['title' => 'Hello', 'description' => 'A card'],
        ]);
    }

    public function testSendRcsValidatesTo(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');
        $client->messages()->send([
            'channel' => 'rcs',
            'to' => 'not-a-number',
            'text' => 'Hello!',
        ]);
    }

    public function testSendRcsNotSupportedMapsToValidationException(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unprocessable Entity',
                new Request('POST', '/messages'),
                new Response(422, [], json_encode([
                    'error' => 'rcs_not_supported_for_recipient',
                    'message' => "This recipient's device or network doesn't support RCS.",
                ]))
            ),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("doesn't support RCS");
        $client->messages()->send([
            'channel' => 'rcs',
            'to' => '+15551234567',
            'text' => 'RCS or nothing',
            'fallbackToSms' => false,
        ]);
    }
}
