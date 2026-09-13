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
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Sendly\Sendly;
use Sendly\Resources\Calls;
use Sendly\Resources\CallBilling;
use Sendly\Resources\CallDirection;
use Sendly\Resources\CallErrorCode;
use Sendly\Resources\CallHandledBy;
use Sendly\Resources\CallKind;
use Sendly\Resources\CallRecordingStatus;
use Sendly\Resources\CallStatus;
use Sendly\Exceptions\SendlyException;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\NotFoundException;
use Sendly\Exceptions\InsufficientCreditsException;
use Sendly\Exceptions\RateLimitException;
use ReflectionClass;

/**
 * Tests for the Calls resource: create(), list(), get(), hangup(), recording()
 */
class CallsTest extends TestCase
{
    private const CALL_ID = '6f1c2d3e-4a5b-4c6d-8e9f-0a1b2c3d4e5f';
    private const AGENT_ID = '3c4d5e6f-7081-4293-a4b5-c6d7e8f90a1b';

    /** @var array<int, array{request: RequestInterface, response: ?Response}> */
    private array $history = [];

    private function createMockClient(array $responses, array $options = []): Sendly
    {
        $this->history = [];
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($this->history));
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new Sendly('test_api_key', $options);

        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('httpClient');
        $property->setValue($client, $httpClient);

        return $client;
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[count($this->history) - 1]['request'];
    }

    private function sentBody(): mixed
    {
        return json_decode((string) $this->lastRequest()->getBody(), true);
    }

    private function apiError(string $method, string $path, int $status, string $code, string $message, array $extra = []): RequestException
    {
        return new RequestException(
            'HTTP ' . $status,
            new Request($method, $path),
            new Response($status, [], json_encode(array_merge([
                'error' => $code,
                'message' => $message,
            ], $extra)))
        );
    }

    private function call(array $overrides = []): array
    {
        return array_merge([
            'id' => self::CALL_ID,
            'object' => 'call',
            'kind' => 'pstn',
            'direction' => 'outbound',
            'status' => 'ringing',
            'handledBy' => 'agent',
            'agentId' => self::AGENT_ID,
            'from' => '+15555550188',
            'to' => '+15555550123',
            'callerName' => 'Front Desk',
            'calleeName' => '+15555550123',
            'startedAt' => '2026-09-12T14:03:11.000Z',
            'answeredAt' => null,
            'endedAt' => null,
            'durationSecs' => 0,
            'creditsCharged' => 0,
            'billing' => 'metered',
            'hangupClass' => null,
            'recordingStatus' => null,
            'metadata' => [],
        ], $overrides);
    }

    // ==================== resource registration ====================

    public function testCallsResourceIsRegistered(): void
    {
        $client = new Sendly('test_api_key');
        $this->assertInstanceOf(Calls::class, $client->calls);
        $this->assertInstanceOf(Calls::class, $client->calls());
        $this->assertSame($client->calls, $client->calls());
    }

    public function testEnumConstantsMatchTheWire(): void
    {
        $this->assertSame('ringing', CallStatus::RINGING);
        $this->assertSame('no_answer', CallStatus::NO_ANSWER);
        $this->assertSame('outbound', CallDirection::OUTBOUND);
        $this->assertSame('pstn', CallKind::PSTN);
        $this->assertSame('internal', CallKind::INTERNAL);
        $this->assertSame('agent', CallHandledBy::AGENT);
        $this->assertSame('dashboard', CallHandledBy::DASHBOARD);
        $this->assertSame('metered', CallBilling::METERED);
        $this->assertSame('unbilled', CallBilling::UNBILLED);
        $this->assertSame('none', CallRecordingStatus::NONE);
        $this->assertSame('ready', CallRecordingStatus::READY);
        $this->assertSame('voice_not_enabled', CallErrorCode::VOICE_NOT_ENABLED);
        $this->assertSame('e911_required', CallErrorCode::E911_REQUIRED);
        $this->assertSame('call_not_found', CallErrorCode::CALL_NOT_FOUND);
    }

    // ==================== create() ====================

    public function testCreatePostsTheCallBody(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->call([
                'metadata' => ['crmId' => 'lead_8812'],
            ]))),
        ]);

        $result = $client->calls()->create([
            'to' => '+15555550123',
            'agentId' => self::AGENT_ID,
            'from' => '+15555550188',
            'context' => 'You are calling Jordan to confirm the 3pm appointment on Tuesday.',
            'metadata' => ['crmId' => 'lead_8812'],
        ]);

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/calls', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([
            'to' => '+15555550123',
            'agentId' => self::AGENT_ID,
            'from' => '+15555550188',
            'context' => 'You are calling Jordan to confirm the 3pm appointment on Tuesday.',
            'metadata' => ['crmId' => 'lead_8812'],
        ], $this->sentBody());
        $this->assertTrue($this->lastRequest()->hasHeader('Idempotency-Key'));

        $this->assertSame(self::CALL_ID, $result['id']);
        $this->assertSame('call', $result['object']);
        $this->assertSame(CallStatus::RINGING, $result['status']);
        $this->assertSame(CallHandledBy::AGENT, $result['handledBy']);
        $this->assertSame(CallBilling::METERED, $result['billing']);
        $this->assertSame(0, $result['creditsCharged']);
        $this->assertSame(['crmId' => 'lead_8812'], $result['metadata']);
        $this->assertArrayNotHasKey('transcript', $result);
    }

    public function testCreateSendsOnlyTheFieldsGiven(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->call())),
        ]);

        $client->calls()->create(['to' => '+15555550123', 'agentId' => self::AGENT_ID]);

        $this->assertSame(['to' => '+15555550123', 'agentId' => self::AGENT_ID], $this->sentBody());
    }

    public function testCreateDropsEmptyMetadata(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->call())),
        ]);

        $client->calls()->create(['to' => '+15555550123', 'agentId' => self::AGENT_ID, 'metadata' => []]);

        $this->assertArrayNotHasKey('metadata', $this->sentBody());
    }

    public function testCreateHonoursCallerIdempotencyKey(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->call())),
        ]);

        $client->calls()->create(['to' => '+15555550123', 'agentId' => self::AGENT_ID], 'confirm-lead-8812');

        $this->assertSame('confirm-lead-8812', $this->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testCreateRequiresTo(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('to is required');
        $client->calls()->create(['agentId' => self::AGENT_ID]);
    }

    public function testCreateRequiresAgentId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('agentId is required');
        $client->calls()->create(['to' => '+15555550123']);
    }

    public function testCreateDoesNotValidatePhoneFormatClientSide(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->call(['to' => '+15555550123']))),
        ]);

        $client->calls()->create(['to' => '555-555-0123', 'agentId' => self::AGENT_ID]);

        $this->assertSame('555-555-0123', $this->sentBody()['to']);
    }

    public function testCreateInsufficientCreditsMapsToTypedException(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/calls', 402, 'insufficient_credits', 'Calls cost 10 credits a minute. Current balance: 4.', [
                'creditsNeeded' => 10,
                'currentBalance' => 4,
            ]),
        ]);

        try {
            $client->calls()->create(['to' => '+15555550123', 'agentId' => self::AGENT_ID]);
            $this->fail('Expected InsufficientCreditsException');
        } catch (InsufficientCreditsException $e) {
            $this->assertSame('Calls cost 10 credits a minute. Current balance: 4.', $e->getMessage());
            $this->assertSame(CallErrorCode::INSUFFICIENT_CREDITS, $e->getApiErrorCode());
        }
    }

    public function testCreateE911RequiredSurfacesStatusAndCode(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/calls', 428, 'e911_required', "Register an emergency address for this number before placing calls. It's required by US law."),
        ]);

        try {
            $client->calls()->create(['to' => '+15555550123', 'agentId' => self::AGENT_ID]);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(428, $e->getCode());
            $this->assertSame(CallErrorCode::E911_REQUIRED, $e->getApiErrorCode());
            $this->assertStringContainsString('emergency address', $e->getMessage());
        }

        $this->assertCount(1, $this->history);
    }

    public function testCreateLinesBusySurfacesStatusAndCode(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/calls', 409, 'lines_busy', "Your workspace's lines are all in use. Try again in a moment."),
        ]);

        try {
            $client->calls()->create(['to' => '+15555550123', 'agentId' => self::AGENT_ID]);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame(CallErrorCode::LINES_BUSY, $e->getApiErrorCode());
        }

        $this->assertCount(1, $this->history);
    }

    public function testCreateAgentRequiredMapsToValidationException(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/calls', 400, 'agent_required', 'Calls placed over the API are answered by an AI agent. Pass agentId.'),
        ]);

        try {
            $client->calls()->create(['to' => '+15555550123', 'agentId' => self::AGENT_ID]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(CallErrorCode::AGENT_REQUIRED, $e->getApiErrorCode());
        }
    }

    public function testCreateDailyCallLimitMapsToRateLimitException(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/calls', 429, 'daily_call_limit', "Today's calling limit has been reached. Try again tomorrow."),
        ]);

        try {
            $client->calls()->create(['to' => '+15555550123', 'agentId' => self::AGENT_ID]);
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(CallErrorCode::DAILY_CALL_LIMIT, $e->getApiErrorCode());
        }
    }

    public function testCreateWhileVoiceIsDarkThrowsNotFound(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/calls', 404, 'voice_not_enabled', 'Voice is not enabled for your account.'),
        ]);

        try {
            $client->calls()->create(['to' => '+15555550123', 'agentId' => self::AGENT_ID]);
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(CallErrorCode::VOICE_NOT_ENABLED, $e->getApiErrorCode());
        }
    }

    public function testCreateWithTestKeySurfacesLiveKeyRequired(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/calls', 403, 'live_key_required', 'Phone calls need a live API key.'),
        ]);

        try {
            $client->calls()->create(['to' => '+15555550123', 'agentId' => self::AGENT_ID]);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame(CallErrorCode::LIVE_KEY_REQUIRED, $e->getApiErrorCode());
        }

        $this->assertCount(1, $this->history);
    }

    // ==================== list() ====================

    public function testListWithoutOptionsSendsNoQuery(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [$this->call()],
                'pagination' => ['total' => 1, 'limit' => 50, 'offset' => 0, 'hasMore' => false],
            ])),
        ]);

        $result = $client->calls()->list();

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/calls', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('', $this->lastRequest()->getUri()->getQuery());
        $this->assertCount(1, $result['data']);
        $this->assertFalse($result['pagination']['hasMore']);
    }

    public function testListEncodesEveryFilter(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [$this->call(['status' => 'completed'])],
                'pagination' => ['total' => 132, 'limit' => 10, 'offset' => 0, 'hasMore' => true],
            ])),
        ]);

        $result = $client->calls()->list([
            'limit' => 10,
            'offset' => 0,
            'status' => CallStatus::COMPLETED,
            'direction' => CallDirection::OUTBOUND,
            'kind' => CallKind::PSTN,
            'agentId' => self::AGENT_ID,
            'to' => '+15555550123',
            'from' => '+15555550188',
        ]);

        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        $this->assertSame([
            'limit' => '10',
            'offset' => '0',
            'status' => 'completed',
            'direction' => 'outbound',
            'kind' => 'pstn',
            'agentId' => self::AGENT_ID,
            'to' => '+15555550123',
            'from' => '+15555550188',
        ], $query);
        $this->assertStringContainsString('to=%2B15555550123', $this->lastRequest()->getUri()->getQuery());

        $this->assertSame(132, $result['pagination']['total']);
        $this->assertTrue($result['pagination']['hasMore']);
        $this->assertSame('completed', $result['data'][0]['status']);
    }

    public function testListKeepsZeroOffset(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [],
                'pagination' => ['total' => 0, 'limit' => 50, 'offset' => 0, 'hasMore' => false],
            ])),
        ]);

        $client->calls()->list(['offset' => 0]);

        $this->assertSame('offset=0', $this->lastRequest()->getUri()->getQuery());
    }

    // ==================== get() ====================

    public function testGetReturnsTranscriptForAgentCalls(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->call([
                'status' => 'completed',
                'answeredAt' => '2026-09-12T14:03:19.000Z',
                'endedAt' => '2026-09-12T14:05:02.000Z',
                'durationSecs' => 103,
                'creditsCharged' => 20,
                'billing' => 'settled',
                'hangupClass' => 'agent_agent_hangup',
                'recordingStatus' => 'ready',
                'transcript' => [
                    ['speaker' => 'agent', 'text' => 'Hi Jordan, this is the front desk.', 'atMs' => 1200],
                    ['speaker' => 'caller', 'text' => 'Hi, yes, 3pm works.', 'atMs' => 4800],
                ],
            ]))),
        ]);

        $result = $client->calls()->get(self::CALL_ID);

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/calls/' . self::CALL_ID, $this->lastRequest()->getUri()->getPath());
        $this->assertSame(CallStatus::COMPLETED, $result['status']);
        $this->assertSame(CallBilling::SETTLED, $result['billing']);
        $this->assertSame('agent_agent_hangup', $result['hangupClass']);
        $this->assertSame(CallRecordingStatus::READY, $result['recordingStatus']);
        $this->assertCount(2, $result['transcript']);
        $this->assertSame('caller', $result['transcript'][1]['speaker']);
        $this->assertSame(4800, $result['transcript'][1]['atMs']);
    }

    public function testGetOmitsTranscriptForDashboardCalls(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->call([
                'direction' => 'inbound',
                'handledBy' => 'dashboard',
                'agentId' => null,
                'status' => 'completed',
                'billing' => 'settled',
            ]))),
        ]);

        $result = $client->calls()->get(self::CALL_ID);

        $this->assertSame(CallHandledBy::DASHBOARD, $result['handledBy']);
        $this->assertNull($result['agentId']);
        $this->assertArrayNotHasKey('transcript', $result);
    }

    public function testGetPreservesNullNumbersOnInternalCalls(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->call([
                'kind' => 'internal',
                'handledBy' => 'dashboard',
                'agentId' => null,
                'from' => null,
                'to' => null,
                'billing' => 'unbilled',
            ]))),
        ]);

        $result = $client->calls()->get(self::CALL_ID);

        $this->assertSame(CallKind::INTERNAL, $result['kind']);
        $this->assertNull($result['from']);
        $this->assertNull($result['to']);
        $this->assertSame(CallBilling::UNBILLED, $result['billing']);
    }

    public function testGetPercentEncodesTheId(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->call())),
        ]);

        $client->calls()->get('../account/keys');

        $this->assertSame('/api/v1/calls/..%2Faccount%2Fkeys', $this->lastRequest()->getUri()->getPath());
    }

    public function testGetRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Call ID is required');
        $client->calls()->get('');
    }

    public function testGetUnknownCallThrowsNotFound(): void
    {
        $client = $this->createMockClient([
            $this->apiError('GET', '/calls/nope', 404, 'call_not_found', 'No call with that id is in this workspace.'),
        ]);

        try {
            $client->calls()->get('nope');
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(CallErrorCode::CALL_NOT_FOUND, $e->getApiErrorCode());
            $this->assertSame('No call with that id is in this workspace.', $e->getMessage());
        }
    }

    // ==================== hangup() ====================

    public function testHangupPostsWithEmptyBody(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->call([
                'status' => 'cancelled',
                'endedAt' => '2026-09-12T14:03:30.000Z',
                'billing' => 'settled',
                'hangupClass' => 'caller_cancelled',
            ]))),
        ]);

        $result = $client->calls()->hangup(self::CALL_ID);

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/calls/' . self::CALL_ID . '/hangup', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([], $this->sentBody());
        $this->assertTrue($this->lastRequest()->hasHeader('Idempotency-Key'));
        $this->assertSame(CallStatus::CANCELLED, $result['status']);
        $this->assertSame('caller_cancelled', $result['hangupClass']);
    }

    public function testHangupActiveCallCompletes(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->call([
                'status' => 'completed',
                'hangupClass' => 'normal',
                'billing' => 'settled',
            ]))),
        ]);

        $result = $client->calls()->hangup(self::CALL_ID, 'hangup-once');

        $this->assertSame('hangup-once', $this->lastRequest()->getHeaderLine('Idempotency-Key'));
        $this->assertSame(CallStatus::COMPLETED, $result['status']);
        $this->assertSame('normal', $result['hangupClass']);
    }

    public function testHangupRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Call ID is required');
        $client->calls()->hangup('');
    }

    // ==================== recording() ====================

    public function testRecordingReady(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'callId' => self::CALL_ID,
                'status' => 'ready',
                'url' => 'https://recordings.example/' . self::CALL_ID . '.ogg?sig=abc',
                'expiresAt' => '2026-09-12T14:10:00.000Z',
                'contentType' => 'audio/ogg',
            ])),
        ]);

        $result = $client->calls()->recording(self::CALL_ID);

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/calls/' . self::CALL_ID . '/recording', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(CallRecordingStatus::READY, $result['status']);
        $this->assertSame('https://recordings.example/' . self::CALL_ID . '.ogg?sig=abc', $result['url']);
        $this->assertSame('2026-09-12T14:10:00.000Z', $result['expiresAt']);
        $this->assertSame('audio/ogg', $result['contentType']);
    }

    public function testRecordingNoneHasNullUrl(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'callId' => self::CALL_ID,
                'status' => 'none',
                'url' => null,
                'expiresAt' => null,
                'contentType' => null,
            ])),
        ]);

        $result = $client->calls()->recording(self::CALL_ID);

        $this->assertSame(CallRecordingStatus::NONE, $result['status']);
        $this->assertArrayHasKey('url', $result);
        $this->assertNull($result['url']);
        $this->assertNull($result['expiresAt']);
        $this->assertNull($result['contentType']);
    }

    public function testRecordingStillRecordingHasNullUrl(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'callId' => self::CALL_ID,
                'status' => 'recording',
                'url' => null,
                'expiresAt' => null,
                'contentType' => null,
            ])),
        ]);

        $result = $client->calls()->recording(self::CALL_ID);

        $this->assertSame(CallRecordingStatus::RECORDING, $result['status']);
        $this->assertNull($result['url']);
    }

    public function testRecordingRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Call ID is required');
        $client->calls()->recording('');
    }

    public function testRecordingOfAnotherWorkspacesCallThrowsNotFound(): void
    {
        $client = $this->createMockClient([
            $this->apiError('GET', '/calls/x/recording', 404, 'call_not_found', 'No call with that id is in this workspace.'),
        ]);

        $this->expectException(NotFoundException::class);
        $client->calls()->recording(self::CALL_ID);
    }
}
