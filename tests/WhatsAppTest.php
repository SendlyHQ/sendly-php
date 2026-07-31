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
use Sendly\Resources\WhatsApp;
use Sendly\Resources\WhatsAppSignup;
use Sendly\Resources\WhatsAppSenders;
use Sendly\Resources\WhatsAppTemplates;
use Sendly\Exceptions\SendlyException;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\NotFoundException;
use ReflectionClass;

/**
 * Tests for WhatsApp resource: signup->create(), signup->get(),
 * senders->list(), templates->list()/create()/update()/delete(), window(),
 * and messages()->send(['channel' => 'whatsapp', ...])
 */
class WhatsAppTest extends TestCase
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

    private function template(array $overrides = []): array
    {
        return array_merge([
            'id' => 'wat_1',
            'name' => 'order_shipped',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'status' => 'PENDING',
            'qualityRating' => null,
            'rejectionReason' => null,
            'createdAt' => '2026-07-30T09:00:00Z',
            'updatedAt' => '2026-07-30T09:00:00Z',
        ], $overrides);
    }

    private function sender(array $overrides = []): array
    {
        return array_merge([
            'phoneNumber' => '+15559876543',
            'displayName' => 'Acme Coffee',
            'status' => 'active',
            'qualityRating' => 'GREEN',
            'createdAt' => '2026-07-30T09:00:00Z',
        ], $overrides);
    }

    // ==================== resource registration ====================

    public function testWhatsAppResourceIsRegistered(): void
    {
        $client = new Sendly('test_api_key');
        $this->assertInstanceOf(WhatsApp::class, $client->whatsapp);
        $this->assertInstanceOf(WhatsApp::class, $client->whatsapp());
        $this->assertSame($client->whatsapp, $client->whatsapp());
        $this->assertInstanceOf(WhatsAppSignup::class, $client->whatsapp->signup);
        $this->assertInstanceOf(WhatsAppSenders::class, $client->whatsapp->senders);
        $this->assertInstanceOf(WhatsAppTemplates::class, $client->whatsapp->templates);
    }

    // ==================== signup->create() ====================

    public function testSignupCreate(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode([
                'id' => 'was_1',
                'connectUrl' => 'https://sendly.live/wa/connect/tok_abc',
                'status' => 'initiated',
            ])),
        ], $capturedBody, $capturedPath);

        $signup = $client->whatsapp()->signup->create('+15559876543');

        $this->assertSame('/whatsapp/signup', $capturedPath);
        $this->assertSame(['phoneNumber' => '+15559876543'], json_decode($capturedBody, true));
        $this->assertSame('was_1', $signup['id']);
        $this->assertSame('https://sendly.live/wa/connect/tok_abc', $signup['connectUrl']);
        $this->assertSame('initiated', $signup['status']);
    }

    public function testSignupCreateValidatesPhone(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');
        $client->whatsapp()->signup->create('5559876543');
    }

    public function testSignupCreateRequiresLiveKey(): void
    {
        // 403 is retried with backoff, so disable retries for this test.
        $client = $this->createMockClient([
            new RequestException(
                'Forbidden',
                new Request('POST', '/whatsapp/signup'),
                new Response(403, [], json_encode([
                    'error' => 'whatsapp_requires_live_key',
                    'message' => 'WhatsApp requires a live API key. Test keys cannot connect WhatsApp senders.',
                ]))
            ),
        ], ['maxRetries' => 0]);

        try {
            $client->whatsapp()->signup->create('+15559876543');
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame('WhatsApp requires a live API key. Test keys cannot connect WhatsApp senders.', $e->getMessage());
        }
    }

    // ==================== signup->get() ====================

    public function testSignupGet(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $client = $this->createCapturingClient([
            new Response(200, [], json_encode([
                'id' => 'was_1',
                'status' => 'active',
                'phoneNumber' => '+15559876543',
                'businessAccountId' => 'waba_123',
                'failureReasons' => null,
                'updatedAt' => '2026-07-30T10:00:00Z',
            ])),
        ], $capturedBody, $capturedPath);

        $signup = $client->whatsapp()->signup->get('was_1');

        $this->assertSame('/whatsapp/signup/was_1', $capturedPath);
        $this->assertSame('active', $signup['status']);
        $this->assertSame('waba_123', $signup['businessAccountId']);
    }

    public function testSignupGetFailedIncludesFailureReasons(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'was_1',
                'status' => 'failed',
                'phoneNumber' => '+15559876543',
                'businessAccountId' => null,
                'failureReasons' => ['The number is already registered to another WhatsApp Business Account'],
                'updatedAt' => '2026-07-30T10:00:00Z',
            ])),
        ]);

        $signup = $client->whatsapp()->signup->get('was_1');

        $this->assertSame('failed', $signup['status']);
        $this->assertNull($signup['businessAccountId']);
        $this->assertSame(['The number is already registered to another WhatsApp Business Account'], $signup['failureReasons']);
    }

    public function testSignupGetRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Signup ID is required');
        $client->whatsapp()->signup->get('');
    }

    public function testSignupGetNotFound(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Not Found',
                new Request('GET', '/whatsapp/signup/was_missing'),
                new Response(404, [], json_encode(['error' => 'signup_not_found']))
            ),
        ]);

        $this->expectException(NotFoundException::class);
        $client->whatsapp()->signup->get('was_missing');
    }

    // ==================== senders->list() ====================

    public function testSendersList(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $client = $this->createCapturingClient([
            new Response(200, [], json_encode([
                'senders' => [
                    $this->sender(),
                    $this->sender(['phoneNumber' => '+15551230000', 'displayName' => null, 'status' => 'pending', 'qualityRating' => null]),
                ],
            ])),
        ], $capturedBody, $capturedPath);

        $result = $client->whatsapp()->senders->list();

        $this->assertSame('/whatsapp/senders', $capturedPath);
        $this->assertCount(2, $result['senders']);
        $this->assertSame('+15559876543', $result['senders'][0]['phoneNumber']);
        $this->assertSame('Acme Coffee', $result['senders'][0]['displayName']);
        $this->assertSame('GREEN', $result['senders'][0]['qualityRating']);
        $this->assertSame('pending', $result['senders'][1]['status']);
        $this->assertNull($result['senders'][1]['displayName']);
    }

    public function testSendersListEmpty(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['senders' => []])),
        ]);

        $result = $client->whatsapp()->senders->list();

        $this->assertSame([], $result['senders']);
    }

    public function testSendersListAbsentUntilChannelEnabled(): void
    {
        // Flag-dark accounts see the whole surface as absent (404).
        $client = $this->createMockClient([
            new RequestException(
                'Not Found',
                new Request('GET', '/whatsapp/senders'),
                new Response(404, [], json_encode(['error' => 'not_found']))
            ),
        ]);

        $this->expectException(NotFoundException::class);
        $client->whatsapp()->senders->list();
    }

    // ==================== templates->list() ====================

    public function testTemplatesList(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $client = $this->createCapturingClient([
            new Response(200, [], json_encode([
                'templates' => [
                    $this->template(),
                    $this->template(['id' => 'wat_2', 'name' => 'otp_code', 'category' => 'AUTHENTICATION', 'status' => 'APPROVED', 'qualityRating' => 'GREEN']),
                ],
            ])),
        ], $capturedBody, $capturedPath);

        $result = $client->whatsapp()->templates->list();

        $this->assertSame('/whatsapp/templates', $capturedPath);
        $this->assertCount(2, $result['templates']);
        $this->assertSame('wat_2', $result['templates'][1]['id']);
        $this->assertSame('APPROVED', $result['templates'][1]['status']);
    }

    // ==================== templates->create() ====================

    public function testTemplatesCreate(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode(
                $this->template(['warnings' => ['Display name is not approved yet']])
            )),
        ], $capturedBody, $capturedPath);

        $template = $client->whatsapp()->templates->create([
            'sender' => '+15559876543',
            'name' => 'order_shipped',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'body' => 'Hi {{1}}, your order {{2}} has shipped!',
            'footer' => 'Reply STOP to opt out',
            'buttons' => [
                ['type' => 'url', 'text' => 'Track order', 'url' => 'https://acme.example/orders/{{1}}', 'example' => ['4821']],
            ],
            'examples' => ['1' => 'Sam', '2' => '#4821'],
        ]);

        $this->assertSame('/whatsapp/templates', $capturedPath);
        $sentBody = json_decode($capturedBody, true);
        $this->assertSame('+15559876543', $sentBody['sender']);
        $this->assertSame('order_shipped', $sentBody['name']);
        $this->assertSame('en_US', $sentBody['language']);
        $this->assertSame('UTILITY', $sentBody['category']);
        $this->assertSame('Hi {{1}}, your order {{2}} has shipped!', $sentBody['body']);
        $this->assertSame('Reply STOP to opt out', $sentBody['footer']);
        $this->assertSame('url', $sentBody['buttons'][0]['type']);
        $this->assertSame(['1' => 'Sam', '2' => '#4821'], $sentBody['examples']);
        $this->assertSame('PENDING', $template['status']);
        $this->assertSame(['Display name is not approved yet'], $template['warnings']);
    }

    public function testTemplatesCreateSendsOnlyProvidedFields(): void
    {
        $capturedBody = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode($this->template())),
        ], $capturedBody);

        $client->whatsapp()->templates->create([
            'sender' => '+15559876543',
            'name' => 'order_shipped',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'body' => 'Your order has shipped!',
        ]);

        $sentBody = json_decode($capturedBody, true);
        $this->assertArrayNotHasKey('footer', $sentBody);
        $this->assertArrayNotHasKey('header', $sentBody);
        $this->assertArrayNotHasKey('buttons', $sentBody);
        $this->assertArrayNotHasKey('examples', $sentBody);
    }

    public function testTemplatesCreateRequiresSender(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sender is required');
        $client->whatsapp()->templates->create([
            'name' => 'order_shipped',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'body' => 'Your order has shipped!',
        ]);
    }

    public function testTemplatesCreateRequiresName(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('name is required');
        $client->whatsapp()->templates->create([
            'sender' => '+15559876543',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'body' => 'Your order has shipped!',
        ]);
    }

    public function testTemplatesCreateRequiresLanguage(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('language is required');
        $client->whatsapp()->templates->create([
            'sender' => '+15559876543',
            'name' => 'order_shipped',
            'category' => 'UTILITY',
            'body' => 'Your order has shipped!',
        ]);
    }

    public function testTemplatesCreateRequiresCategory(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('category is required');
        $client->whatsapp()->templates->create([
            'sender' => '+15559876543',
            'name' => 'order_shipped',
            'language' => 'en_US',
            'body' => 'Your order has shipped!',
        ]);
    }

    public function testTemplatesCreateRequiresBody(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('body is required');
        $client->whatsapp()->templates->create([
            'sender' => '+15559876543',
            'name' => 'order_shipped',
            'language' => 'en_US',
            'category' => 'UTILITY',
        ]);
    }

    public function testTemplatesCreateValidatesSenderPhone(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');
        $client->whatsapp()->templates->create([
            'sender' => 'not-a-number',
            'name' => 'order_shipped',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'body' => 'Your order has shipped!',
        ]);
    }

    public function testTemplatesCreateNameLockedMapsToValidationException(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Bad Request',
                new Request('POST', '/whatsapp/templates'),
                new Response(400, [], json_encode([
                    'error' => 'template_name_locked',
                    'message' => 'This template name was recently deleted and its name is locked for reuse for up to 30 days. Edit an existing template instead of re-creating it.',
                ]))
            ),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('locked for reuse');
        $client->whatsapp()->templates->create([
            'sender' => '+15559876543',
            'name' => 'order_shipped',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'body' => 'Your order has shipped!',
        ]);
    }

    // ==================== templates->update() ====================

    public function testTemplatesUpdate(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $capturedQuery = null;
        $capturedMethod = null;
        $client = $this->createCapturingClient([
            new Response(200, [], json_encode(
                $this->template(['status' => 'PENDING', 'updatedAt' => '2026-07-30T11:00:00Z'])
            )),
        ], $capturedBody, $capturedPath, $capturedQuery, $capturedMethod);

        $template = $client->whatsapp()->templates->update('wat_1', [
            'body' => 'Hi {{1}}, your order {{2}} is on its way!',
            'examples' => ['1' => 'Sam', '2' => '#4821'],
        ]);

        $this->assertSame('/whatsapp/templates/wat_1', $capturedPath);
        $this->assertSame('PATCH', $capturedMethod);
        $sentBody = json_decode($capturedBody, true);
        $this->assertSame('Hi {{1}}, your order {{2}} is on its way!', $sentBody['body']);
        $this->assertSame(['1' => 'Sam', '2' => '#4821'], $sentBody['examples']);
        $this->assertArrayNotHasKey('footer', $sentBody);
        $this->assertArrayNotHasKey('header', $sentBody);
        $this->assertArrayNotHasKey('buttons', $sentBody);
        $this->assertSame('PENDING', $template['status']);
    }

    public function testTemplatesUpdateRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Template ID is required');
        $client->whatsapp()->templates->update('', ['body' => 'New body']);
    }

    // ==================== templates->delete() ====================

    public function testTemplatesDelete(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $capturedQuery = null;
        $capturedMethod = null;
        $client = $this->createCapturingClient([
            new Response(200, [], json_encode(['id' => 'wat_1', 'deleted' => true])),
        ], $capturedBody, $capturedPath, $capturedQuery, $capturedMethod);

        $result = $client->whatsapp()->templates->delete('wat_1');

        $this->assertSame('/whatsapp/templates/wat_1', $capturedPath);
        $this->assertSame('DELETE', $capturedMethod);
        $this->assertSame('wat_1', $result['id']);
        $this->assertTrue($result['deleted']);
    }

    public function testTemplatesDeleteRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Template ID is required');
        $client->whatsapp()->templates->delete('');
    }

    // ==================== window() ====================

    public function testWindowOpen(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $capturedQuery = null;
        $client = $this->createCapturingClient([
            new Response(200, [], json_encode([
                'open' => true,
                'expiresAt' => '2026-07-30T18:22:00Z',
            ])),
        ], $capturedBody, $capturedPath, $capturedQuery);

        $window = $client->whatsapp()->window('+15559876543', '+15551234567');

        $this->assertSame('/whatsapp/window', $capturedPath);
        parse_str($capturedQuery, $sentQuery);
        $this->assertSame(['from' => '+15559876543', 'to' => '+15551234567'], $sentQuery);
        $this->assertTrue($window['open']);
        $this->assertSame('2026-07-30T18:22:00Z', $window['expiresAt']);
    }

    public function testWindowClosed(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['open' => false, 'expiresAt' => null])),
        ]);

        $window = $client->whatsapp()->window('+15559876543', '+15551234567');

        $this->assertFalse($window['open']);
        $this->assertNull($window['expiresAt']);
    }

    public function testWindowValidatesFrom(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');
        $client->whatsapp()->window('bad', '+15551234567');
    }

    public function testWindowValidatesTo(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');
        $client->whatsapp()->window('+15559876543', 'bad');
    }

    // ==================== messages()->send(channel: whatsapp) ====================

    private function whatsappMessage(array $overrides = []): array
    {
        return array_merge([
            'id' => 'msg_wa_1',
            'channel' => 'whatsapp',
            'message_format' => 'whatsapp',
            'to' => '+15551234567',
            'from' => '+15559876543',
            'text' => null,
            'status' => 'queued',
            'segments' => 1,
            'creditsUsed' => 6,
            'whatsapp' => [
                'kind' => 'template',
                'template' => ['name' => 'order_shipped', 'language' => 'en_US', 'category' => 'utility'],
                'messageId' => null,
            ],
            'createdAt' => '2026-07-30T09:12:00Z',
            'metadata' => [],
        ], $overrides);
    }

    public function testSendWhatsAppTemplateMessage(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode($this->whatsappMessage())),
        ], $capturedBody, $capturedPath);

        $message = $client->messages()->send([
            'channel' => 'whatsapp',
            'to' => '+15551234567',
            'from' => '+15559876543',
            'template' => [
                'name' => 'order_shipped',
                'language' => 'en_US',
                'variables' => ['1' => 'Acme Inc', '2' => '#4821'],
                'buttons' => [['index' => 0, 'variables' => ['1' => '4821']]],
            ],
        ]);

        $this->assertSame('/messages', $capturedPath);
        $sentBody = json_decode($capturedBody, true);
        $this->assertSame('whatsapp', $sentBody['channel']);
        $this->assertSame('+15551234567', $sentBody['to']);
        $this->assertSame('+15559876543', $sentBody['from']);
        $this->assertSame('order_shipped', $sentBody['template']['name']);
        $this->assertSame(['1' => 'Acme Inc', '2' => '#4821'], $sentBody['template']['variables']);
        $this->assertSame(0, $sentBody['template']['buttons'][0]['index']);
        $this->assertArrayNotHasKey('text', $sentBody);
        $this->assertArrayNotHasKey('mediaUrls', $sentBody);

        $this->assertIsArray($message);
        $this->assertSame('msg_wa_1', $message['id']);
        $this->assertSame('whatsapp', $message['channel']);
        $this->assertSame('template', $message['whatsapp']['kind']);
        $this->assertSame('utility', $message['whatsapp']['template']['category']);
        $this->assertSame(6, $message['creditsUsed']);
    }

    public function testSendWhatsAppFreeFormText(): void
    {
        $capturedBody = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode($this->whatsappMessage([
                'text' => 'Your table is ready!',
                'creditsUsed' => 1,
                'whatsapp' => ['kind' => 'text', 'messageId' => null],
            ]))),
        ], $capturedBody);

        $message = $client->messages()->send([
            'channel' => 'whatsapp',
            'to' => '+15551234567',
            'from' => '+15559876543',
            'text' => 'Your table is ready!',
            'metadata' => ['orderId' => 'ord_812'],
        ]);

        $sentBody = json_decode($capturedBody, true);
        $this->assertSame('Your table is ready!', $sentBody['text']);
        $this->assertSame(['orderId' => 'ord_812'], $sentBody['metadata']);
        $this->assertArrayNotHasKey('template', $sentBody);
        $this->assertSame('text', $message['whatsapp']['kind']);
        $this->assertSame(1, $message['creditsUsed']);
    }

    public function testSendWhatsAppMediaWithCaption(): void
    {
        $capturedBody = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode($this->whatsappMessage([
                'whatsapp' => ['kind' => 'media', 'messageId' => null],
            ]))),
        ], $capturedBody);

        $message = $client->messages()->send([
            'channel' => 'whatsapp',
            'to' => '+15551234567',
            'from' => '+15559876543',
            'mediaUrls' => ['https://example.com/receipt.pdf'],
            'text' => 'Here is your receipt.',
        ]);

        $sentBody = json_decode($capturedBody, true);
        $this->assertSame(['https://example.com/receipt.pdf'], $sentBody['mediaUrls']);
        $this->assertSame('Here is your receipt.', $sentBody['text']);
        $this->assertSame('media', $message['whatsapp']['kind']);
    }

    public function testSendWhatsAppRequiresContent(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Provide 'text', 'mediaUrls', or 'template'");
        $client->messages()->send([
            'channel' => 'whatsapp',
            'to' => '+15551234567',
            'from' => '+15559876543',
        ]);
    }

    public function testSendWhatsAppRequiresFrom(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');
        $client->messages()->send([
            'channel' => 'whatsapp',
            'to' => '+15551234567',
            'text' => 'Your table is ready!',
        ]);
    }

    public function testSendWhatsAppValidatesTo(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');
        $client->messages()->send([
            'channel' => 'whatsapp',
            'to' => 'not-a-number',
            'from' => '+15559876543',
            'text' => 'Your table is ready!',
        ]);
    }

    public function testSendWhatsAppWindowClosedMapsToValidationException(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unprocessable Entity',
                new Request('POST', '/messages'),
                new Response(422, [], json_encode([
                    'error' => 'whatsapp_window_closed',
                    'message' => 'The 24-hour window with this recipient is closed. Send an approved template to start a new conversation.',
                ]))
            ),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The 24-hour window with this recipient is closed');
        $client->messages()->send([
            'channel' => 'whatsapp',
            'to' => '+15551234567',
            'from' => '+15559876543',
            'text' => 'Your table is ready!',
        ]);
    }
}
