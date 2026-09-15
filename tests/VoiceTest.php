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
use Sendly\Resources\CallErrorCode;
use Sendly\Resources\Voice;
use Sendly\Resources\VoiceAgents;
use Sendly\Resources\VoiceMode;
use Sendly\Resources\VoiceNumbers;
use Sendly\Resources\VoiceVoices;
use Sendly\Exceptions\SendlyException;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\NotFoundException;
use ReflectionClass;

/**
 * Tests for the Voice resource: numbers, agents and voices
 */
class VoiceTest extends TestCase
{
    private const NUMBER_ID = '5f0c1c2e-2a44-4d4b-9d51-0a9b0f6f4a11';
    private const PHONE = '+15555550188';
    private const ENCODED_PHONE = '%2B15555550188';
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

    private function voiceNumber(array $overrides = []): array
    {
        return array_merge([
            'id' => self::NUMBER_ID,
            'object' => 'voice_number',
            'phoneNumber' => self::PHONE,
            'phoneNumberType' => 'local',
            'countryCode' => 'US',
            'isDefault' => true,
            'voiceEnabled' => true,
            'voiceMode' => 'agent',
            'agentId' => self::AGENT_ID,
            'emergencyAddress' => [
                'status' => 'active',
                'address' => [
                    'street' => '500 Example Ave',
                    'unit' => 'Suite 2',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'zip' => '78701',
                    'country' => 'US',
                ],
            ],
            'ratePerMinute' => ['inbound' => 2, 'outbound' => 2, 'agent' => 10],
        ], $overrides);
    }

    private function agent(array $overrides = []): array
    {
        return array_merge([
            'id' => self::AGENT_ID,
            'object' => 'voice_agent',
            'name' => 'Front desk',
            'enabled' => true,
            'voice' => 'ashley',
            'voiceLabel' => 'Ashley (US, warm)',
            'language' => 'en-US',
            'greeting' => 'Thanks for calling Acme, how can I help?',
            'instructions' => 'Answer questions about opening hours.',
            'tools' => ['sendSms' => true, 'transferTo' => null],
            'canSendSms' => true,
            'callsHandled' => 12,
            'avgDurationSecs' => 74,
            'createdAt' => '2026-09-14T17:00:00.000Z',
            'updatedAt' => '2026-09-14T17:05:00.000Z',
        ], $overrides);
    }

    // ==================== resource registration ====================

    public function testVoiceResourceIsRegistered(): void
    {
        $client = new Sendly('test_api_key');

        $this->assertInstanceOf(Voice::class, $client->voice);
        $this->assertSame($client->voice, $client->voice());
        $this->assertInstanceOf(VoiceNumbers::class, $client->voice->numbers);
        $this->assertInstanceOf(VoiceAgents::class, $client->voice->agents);
        $this->assertInstanceOf(VoiceVoices::class, $client->voice->voices);
        $this->assertSame($client->voice->numbers, $client->voice()->numbers());
        $this->assertSame($client->voice->agents, $client->voice()->agents());
        $this->assertSame($client->voice->voices, $client->voice()->voices());
    }

    public function testConstantsMatchTheWire(): void
    {
        $this->assertSame('none', VoiceMode::NONE);
        $this->assertSame('ring_dashboard', VoiceMode::RING_DASHBOARD);
        $this->assertSame('agent', VoiceMode::AGENT);
        $this->assertSame('number_not_found', CallErrorCode::NUMBER_NOT_FOUND);
        $this->assertSame('agent_required', CallErrorCode::AGENT_REQUIRED);
        $this->assertSame('agent_in_use', CallErrorCode::AGENT_IN_USE);
        $this->assertSame('agent_limit', CallErrorCode::AGENT_LIMIT);
        $this->assertSame('invalid_voice_mode', CallErrorCode::INVALID_VOICE_MODE);
        $this->assertSame('invalid_address', CallErrorCode::INVALID_ADDRESS);
        $this->assertSame('e911_not_applicable', CallErrorCode::E911_NOT_APPLICABLE);
        $this->assertSame('voice_attach_failed', CallErrorCode::VOICE_ATTACH_FAILED);
        $this->assertSame('carrier_refused', CallErrorCode::CARRIER_REFUSED);
        $this->assertSame('insufficient_permissions', CallErrorCode::INSUFFICIENT_PERMISSIONS);
    }

    // ==================== numbers ====================

    public function testNumbersListReturnsData(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    $this->voiceNumber(),
                    $this->voiceNumber([
                        'id' => '6a1d2e3f-3b55-4e5c-8e62-1b2c3d4e5f60',
                        'phoneNumber' => '+15555550190',
                        'isDefault' => false,
                        'voiceEnabled' => false,
                        'voiceMode' => 'none',
                        'agentId' => null,
                        'emergencyAddress' => null,
                    ]),
                ],
            ])),
        ]);

        $result = $client->voice->numbers->list();

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/voice/numbers', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('', $this->lastRequest()->getUri()->getQuery());
        $this->assertCount(2, $result['data']);
        $this->assertSame($this->voiceNumber(), $result['data'][0]);
        $this->assertSame(VoiceMode::NONE, $result['data'][1]['voiceMode']);
        $this->assertNull($result['data'][1]['emergencyAddress']);
    }

    public function testNumbersGetByPhoneNumberEncodesThePlus(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->voiceNumber())),
        ]);

        $result = $client->voice->numbers->get(self::PHONE);

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/voice/numbers/' . self::ENCODED_PHONE, $this->lastRequest()->getUri()->getPath());
        $this->assertSame('voice_number', $result['object']);
        $this->assertSame(['inbound' => 2, 'outbound' => 2, 'agent' => 10], $result['ratePerMinute']);
        $this->assertSame('active', $result['emergencyAddress']['status']);
        $this->assertSame('Suite 2', $result['emergencyAddress']['address']['unit']);
    }

    public function testNumbersGetById(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->voiceNumber())),
        ]);

        $client->voice()->numbers()->get(self::NUMBER_ID);

        $this->assertSame('/api/v1/voice/numbers/' . self::NUMBER_ID, $this->lastRequest()->getUri()->getPath());
    }

    public function testNumbersGetPercentEncodesPathTraversal(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->voiceNumber())),
        ]);

        $client->voice->numbers->get('../agents/x');

        $this->assertSame('/api/v1/voice/numbers/..%2Fagents%2Fx', $this->lastRequest()->getUri()->getPath());
    }

    public function testNumbersRequireANumber(): void
    {
        $client = new Sendly('test_api_key');
        $calls = [
            fn() => $client->voice->numbers->get(''),
            fn() => $client->voice->numbers->get('   '),
            fn() => $client->voice->numbers->update('', ['voiceEnabled' => true]),
            fn() => $client->voice->numbers->registerEmergencyAddress('', [
                'street' => '500 Example Ave', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701',
            ]),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Expected ValidationException');
            } catch (ValidationException $e) {
                $this->assertSame('Number is required', $e->getMessage());
                $this->assertNull($e->getResponseBody());
            }
        }
    }

    public function testNumbersGetUnknownNumberThrowsNotFound(): void
    {
        $client = $this->createMockClient([
            $this->apiError('GET', '/voice/numbers/x', 404, 'number_not_found', "This number isn't in your workspace."),
        ]);

        try {
            $client->voice->numbers->get(self::PHONE);
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(CallErrorCode::NUMBER_NOT_FOUND, $e->getApiErrorCode());
        }
    }

    public function testNumbersUpdatePatchesCamelCaseKeys(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->voiceNumber())),
        ]);

        $result = $client->voice->numbers->update(self::PHONE, [
            'voiceEnabled' => true,
            'voiceMode' => VoiceMode::AGENT,
            'agentId' => self::AGENT_ID,
            'voiceAgentId' => 'ignored',
            'isDefault' => false,
        ]);

        $this->assertSame('PATCH', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/voice/numbers/' . self::ENCODED_PHONE, $this->lastRequest()->getUri()->getPath());
        $this->assertSame([
            'voiceEnabled' => true,
            'voiceMode' => 'agent',
            'agentId' => self::AGENT_ID,
        ], $this->sentBody());
        $this->assertSame(VoiceMode::AGENT, $result['voiceMode']);
        $this->assertSame(self::AGENT_ID, $result['agentId']);
    }

    public function testNumbersUpdateSendsOnlyTheFieldsGiven(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->voiceNumber(['voiceEnabled' => false, 'voiceMode' => 'none']))),
        ]);

        $client->voice->numbers->update(self::NUMBER_ID, ['voiceEnabled' => false]);

        $this->assertSame(['voiceEnabled' => false], $this->sentBody());
    }

    public function testNumbersUpdateSendsNullAgentIdToClearIt(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->voiceNumber(['voiceMode' => 'ring_dashboard', 'agentId' => null]))),
        ]);

        $client->voice->numbers->update(self::NUMBER_ID, [
            'voiceMode' => VoiceMode::RING_DASHBOARD,
            'voiceEnabled' => null,
            'agentId' => null,
        ]);

        $this->assertSame(['voiceMode' => 'ring_dashboard', 'agentId' => null], $this->sentBody());
    }

    public function testNumbersUpdateWithNoRecognisedKeysSendsAnEmptyObject(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->voiceNumber())),
        ]);

        $client->voice->numbers->update(self::NUMBER_ID, ['isDefault' => true, 'voiceEnabled' => null]);

        $this->assertSame('PATCH', $this->lastRequest()->getMethod());
        $this->assertSame('{}', (string) $this->lastRequest()->getBody());
    }

    public function testNonJsonErrorResponseHasNoResponseBody(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'HTTP 502',
                new Request('PATCH', '/voice/numbers/x'),
                new Response(502, ['Content-Type' => 'text/html'], '<html><body><h1>502 Bad Gateway</h1></body></html>')
            ),
        ], ['maxRetries' => 0]);

        try {
            $client->voice->numbers->update(self::NUMBER_ID, ['voiceMode' => VoiceMode::RING_DASHBOARD]);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertNull($e->getApiErrorCode());
            $this->assertNull($e->getResponseBody());
        }
    }

    public function testNumbersUpdateDoesNotValidateModeClientSide(): void
    {
        $client = $this->createMockClient([
            $this->apiError('PATCH', '/voice/numbers/x', 400, 'invalid_voice_mode', 'voiceMode must be one of none, ring_dashboard, agent.'),
        ]);

        try {
            $client->voice->numbers->update(self::NUMBER_ID, ['voiceMode' => 'voicemail']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(CallErrorCode::INVALID_VOICE_MODE, $e->getApiErrorCode());
        }

        $this->assertSame(['voiceMode' => 'voicemail'], $this->sentBody());
    }

    public function testNumbersUpdateAgentDisabledSurfacesStatusAndCode(): void
    {
        $client = $this->createMockClient([
            $this->apiError('PATCH', '/voice/numbers/x', 409, 'agent_disabled', "That agent is switched off. Turn it on before pointing a number at it."),
        ]);

        try {
            $client->voice->numbers->update(self::PHONE, ['voiceMode' => VoiceMode::AGENT, 'agentId' => self::AGENT_ID]);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame(CallErrorCode::AGENT_DISABLED, $e->getApiErrorCode());
        }

        $this->assertCount(1, $this->history);
    }

    public function testRegisterEmergencyAddressPostsTheAddress(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->voiceNumber([
                'emergencyAddress' => [
                    'status' => 'provisioning',
                    'address' => [
                        'street' => '500 Example Ave',
                        'unit' => 'Suite 2',
                        'city' => 'Austin',
                        'state' => 'TX',
                        'zip' => '78701',
                        'country' => 'US',
                    ],
                ],
            ]))),
        ]);

        $result = $client->voice->numbers->registerEmergencyAddress(self::PHONE, [
            'street' => '500 Example Ave',
            'unit' => 'Suite 2',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701',
            'country' => 'US',
        ]);

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame(
            '/api/v1/voice/numbers/' . self::ENCODED_PHONE . '/emergency-address',
            $this->lastRequest()->getUri()->getPath()
        );
        $this->assertSame([
            'street' => '500 Example Ave',
            'unit' => 'Suite 2',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701',
            'country' => 'US',
        ], $this->sentBody());
        $this->assertTrue($this->lastRequest()->hasHeader('Idempotency-Key'));
        $this->assertSame('provisioning', $result['emergencyAddress']['status']);
    }

    public function testRegisterEmergencyAddressSendsOnlyTheFieldsGiven(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->voiceNumber())),
        ]);

        $client->voice->numbers->registerEmergencyAddress(self::NUMBER_ID, [
            'street' => '500 Example Ave',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701',
            'suite' => 'dropped',
        ], 'e911-number-0188');

        $this->assertSame([
            'street' => '500 Example Ave',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701',
        ], $this->sentBody());
        $this->assertSame('e911-number-0188', $this->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testRegisterEmergencyAddressRequiresEachAddressField(): void
    {
        $client = new Sendly('test_api_key');
        $address = ['street' => '500 Example Ave', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'];

        foreach (['street', 'city', 'state', 'zip'] as $field) {
            foreach ([null, '', '  '] as $value) {
                $params = $address;
                if ($value === null) {
                    unset($params[$field]);
                } else {
                    $params[$field] = $value;
                }

                try {
                    $client->voice->numbers->registerEmergencyAddress(self::PHONE, $params);
                    $this->fail("Expected ValidationException for {$field}");
                } catch (ValidationException $e) {
                    $this->assertSame("{$field} is required", $e->getMessage());
                }
            }
        }
    }

    public function testRegisterEmergencyAddressUnvalidatedAddressCarriesSuggestion(): void
    {
        $suggested = [
            'street' => '500 Example Avenue',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701-1234',
            'country' => 'US',
        ];
        $client = $this->createMockClient([
            $this->apiError('POST', '/voice/numbers/x/emergency-address', 422, 'invalid_address', "We couldn't validate that address.", [
                'suggested' => $suggested,
            ]),
        ]);

        try {
            $client->voice->numbers->registerEmergencyAddress(self::PHONE, [
                'street' => '500 Example Ave',
                'city' => 'Austin',
                'state' => 'TX',
                'zip' => '78701',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(CallErrorCode::INVALID_ADDRESS, $e->getApiErrorCode());
            $this->assertSame($suggested, $e->getResponseBody()['suggested']);
        }

        $this->assertCount(1, $this->history);
    }

    public function testRegisterEmergencyAddressRefusalSurfacesStatusAndCode(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/voice/numbers/x/emergency-address', 502, 'carrier_refused', "The address couldn't be registered. Try again in a moment.", [
                'suggested' => null,
            ]),
        ], ['maxRetries' => 0]);

        try {
            $client->voice->numbers->registerEmergencyAddress(self::PHONE, [
                'street' => '500 Example Ave',
                'city' => 'Austin',
                'state' => 'TX',
                'zip' => '78701',
            ]);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertSame(CallErrorCode::CARRIER_REFUSED, $e->getApiErrorCode());
            $this->assertArrayHasKey('suggested', $e->getResponseBody());
            $this->assertNull($e->getResponseBody()['suggested']);
        }
    }

    // ==================== agents ====================

    public function testAgentsListReturnsData(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['data' => [$this->agent()]])),
        ]);

        $result = $client->voice->agents->list();

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/voice/agents', $this->lastRequest()->getUri()->getPath());
        $this->assertCount(1, $result['data']);
        $this->assertSame($this->agent(), $result['data'][0]);
        $this->assertArrayNotHasKey('llmModel', $result['data'][0]);
    }

    public function testAgentsCreatePostsCamelCaseBody(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->agent(['tools' => ['sendSms' => false, 'transferTo' => '+15555550190']]))),
        ]);

        $result = $client->voice->agents->create([
            'name' => 'Front desk',
            'enabled' => true,
            'voice' => 'ashley',
            'language' => 'en-US',
            'greeting' => 'Thanks for calling Acme, how can I help?',
            'instructions' => 'Answer questions about opening hours.',
            'tools' => ['sendSms' => false, 'transferTo' => '+15555550190', 'send_sms' => true],
            'llmModel' => 'dropped',
        ]);

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/voice/agents', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([
            'name' => 'Front desk',
            'enabled' => true,
            'voice' => 'ashley',
            'language' => 'en-US',
            'greeting' => 'Thanks for calling Acme, how can I help?',
            'instructions' => 'Answer questions about opening hours.',
            'tools' => ['sendSms' => false, 'transferTo' => '+15555550190'],
        ], $this->sentBody());
        $this->assertTrue($this->lastRequest()->hasHeader('Idempotency-Key'));
        $this->assertSame(self::AGENT_ID, $result['id']);
        $this->assertSame('voice_agent', $result['object']);
        $this->assertSame('+15555550190', $result['tools']['transferTo']);
    }

    public function testAgentsCreateSendsOnlyTheFieldsGiven(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->agent())),
        ]);

        $client->voice->agents->create(['name' => 'Front desk', 'greeting' => null, 'tools' => []], 'agent-front-desk');

        $this->assertSame(['name' => 'Front desk'], $this->sentBody());
        $this->assertSame('agent-front-desk', $this->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testAgentsCreateRequiresName(): void
    {
        $client = new Sendly('test_api_key');

        foreach ([[], ['name' => ''], ['name' => '   '], ['voice' => 'ashley']] as $params) {
            try {
                $client->voice->agents->create($params);
                $this->fail('Expected ValidationException');
            } catch (ValidationException $e) {
                $this->assertSame('name is required', $e->getMessage());
            }
        }
    }

    public function testAgentsCreateLimitSurfacesStatusAndCode(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/voice/agents', 409, 'agent_limit', "You've reached the agent limit for this workspace."),
        ]);

        try {
            $client->voice->agents->create(['name' => 'Front desk']);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame(CallErrorCode::AGENT_LIMIT, $e->getApiErrorCode());
        }

        $this->assertCount(1, $this->history);
    }

    public function testAgentsCreateWithTestKeySurfacesLiveKeyRequired(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/voice/agents', 403, 'live_key_required', 'Voice changes need a live API key.'),
        ]);

        try {
            $client->voice->agents->create(['name' => 'Front desk']);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame(CallErrorCode::LIVE_KEY_REQUIRED, $e->getApiErrorCode());
        }
    }

    public function testAgentsGetEncodesTheId(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->agent())),
            new Response(200, [], json_encode($this->agent())),
        ]);

        $result = $client->voice->agents->get(self::AGENT_ID);

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/voice/agents/' . self::AGENT_ID, $this->lastRequest()->getUri()->getPath());
        $this->assertSame('Ashley (US, warm)', $result['voiceLabel']);

        $client->voice->agents->get('../numbers');
        $this->assertSame('/api/v1/voice/agents/..%2Fnumbers', $this->lastRequest()->getUri()->getPath());
    }

    public function testAgentsUpdatePatchesTheSubsetGiven(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->agent(['greeting' => '', 'tools' => ['sendSms' => true, 'transferTo' => null]]))),
        ]);

        $result = $client->voice->agents->update(self::AGENT_ID, [
            'greeting' => '',
            'tools' => ['transferTo' => null],
        ]);

        $this->assertSame('PATCH', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/voice/agents/' . self::AGENT_ID, $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['greeting' => '', 'tools' => ['transferTo' => null]], $this->sentBody());
        $this->assertSame('', $result['greeting']);
    }

    public function testAgentsUpdateWithNoRecognisedKeysSendsAnEmptyObject(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->agent())),
        ]);

        $client->voice->agents->update(self::AGENT_ID, ['canSendSms' => true, 'tools' => []]);

        $this->assertSame('PATCH', $this->lastRequest()->getMethod());
        $this->assertSame('{}', (string) $this->lastRequest()->getBody());
    }

    public function testAgentsDeleteReturnsConfirmation(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['id' => self::AGENT_ID, 'object' => 'voice_agent', 'deleted' => true])),
        ]);

        $result = $client->voice->agents->delete(self::AGENT_ID);

        $this->assertSame('DELETE', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/voice/agents/' . self::AGENT_ID, $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['id' => self::AGENT_ID, 'object' => 'voice_agent', 'deleted' => true], $result);
    }

    public function testAgentsDeleteInUseListsTheNumbers(): void
    {
        $client = $this->createMockClient([
            $this->apiError('DELETE', '/voice/agents/x', 409, 'agent_in_use', 'This agent answers 1 number. Point it elsewhere first.', [
                'numbers' => [self::PHONE],
            ]),
        ]);

        try {
            $client->voice->agents->delete(self::AGENT_ID);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertNotInstanceOf(ValidationException::class, $e);
            $this->assertSame(409, $e->getCode());
            $this->assertSame(CallErrorCode::AGENT_IN_USE, $e->getApiErrorCode());
            $this->assertSame('This agent answers 1 number. Point it elsewhere first.', $e->getMessage());
            $this->assertSame([self::PHONE], $e->getResponseBody()['numbers']);
        }

        $this->assertCount(1, $this->history);
    }

    public function testAgentsRequireAnId(): void
    {
        $client = new Sendly('test_api_key');
        $calls = [
            fn() => $client->voice->agents->get(''),
            fn() => $client->voice->agents->update(' ', ['name' => 'Front desk']),
            fn() => $client->voice->agents->delete(''),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Expected ValidationException');
            } catch (ValidationException $e) {
                $this->assertSame('Agent ID is required', $e->getMessage());
            }
        }
    }

    public function testAgentsWhileVoiceIsDarkThrowsNotFound(): void
    {
        $client = $this->createMockClient([
            $this->apiError('GET', '/voice/agents', 404, 'voice_not_enabled', 'Voice is not enabled for your account.'),
        ]);

        try {
            $client->voice->agents->list();
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(CallErrorCode::VOICE_NOT_ENABLED, $e->getApiErrorCode());
        }
    }

    // ==================== voices ====================

    public function testVoicesListReturnsData(): void
    {
        $voices = [
            ['id' => 'ashley', 'label' => 'Ashley (US, warm)', 'language' => 'en'],
            ['id' => 'marcus', 'label' => 'Marcus (US, calm)', 'language' => 'en'],
        ];
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['data' => $voices])),
        ]);

        $result = $client->voice->voices->list();

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/voice/voices', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($voices, $result['data']);
    }
}
