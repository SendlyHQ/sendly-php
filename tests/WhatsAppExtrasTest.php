<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Sendly\Sendly;
use Sendly\Exceptions\SendlyException;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\RateLimitException;
use Sendly\Exceptions\NotFoundException;
use Sendly\Exceptions\NetworkException;
use ReflectionClass;

/**
 * Tests for the 4.3.0 WhatsApp extras: sender profile photo, conversational
 * components and calling, adding a number to a connected account by code,
 * and the 409 whatsapp_send_unconfirmed send outcome.
 */
class WhatsAppExtrasTest extends TestCase
{
    private const SENDER = '+15555550147';
    private const SENDER_PATH = '/api/v1/whatsapp/senders/%2B15555550147';

    /** @var array<int, array{request: RequestInterface, response: ?Response}> */
    private array $history = [];

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

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

    private function skipSleeps(Sendly $client): void
    {
        (new ReflectionClass($client))->getProperty('sleep')->setValue(
            $client,
            function (int $microseconds): void {
            }
        );
    }

    private function lastRequest(): RequestInterface
    {
        return $this->history[count($this->history) - 1]['request'];
    }

    private function sentBody(): mixed
    {
        return json_decode((string) $this->lastRequest()->getBody(), true);
    }

    private function apiError(string $method, string $path, int $status, array $body, array $headers = []): RequestException
    {
        return new RequestException(
            'HTTP ' . $status,
            new Request($method, $path),
            new Response($status, $headers, json_encode($body))
        );
    }

    private function profile(array $overrides = []): array
    {
        return array_merge([
            'phoneNumber' => self::SENDER,
            'displayName' => 'Acme Coffee',
            'profilePhotoUrl' => 'https://cdn.example.com/wa/profile.png',
            'category' => 'FOOD_AND_GROCERY',
            'about' => 'Fresh roasted coffee, delivered.',
            'description' => null,
            'email' => null,
            'website' => 'https://acme.example',
            'address' => null,
        ], $overrides);
    }

    private function pngFile(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'sendly-wa-photo-');
        $path = $base . '.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 32));
        $this->tempFiles[] = $base;
        $this->tempFiles[] = $path;

        return $path;
    }

    private function verifyingSignup(array $overrides = []): array
    {
        return array_merge([
            'id' => 'was_add_1',
            'status' => 'verifying',
            'phoneNumber' => self::SENDER,
            'businessAccountId' => '102938475610293',
            'failureReasons' => null,
            'verificationMethod' => 'sms',
            'verificationAttemptsRemaining' => 5,
            'updatedAt' => '2026-10-01T09:00:00.000Z',
        ], $overrides);
    }

    // ==================== senders->uploadProfilePhoto() ====================

    public function testUploadProfilePhotoSendsTheFileAsMultipartField(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->profile())),
        ]);
        $file = $this->pngFile();

        $profile = $client->whatsapp()->senders->uploadProfilePhoto(self::SENDER, $file);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::SENDER_PATH . '/profile/photo', $request->getUri()->getPath());
        $this->assertStringStartsWith('multipart/form-data', $request->getHeaderLine('Content-Type'));
        $body = (string) $request->getBody();
        $this->assertStringContainsString('name="file"', $body);
        $this->assertStringContainsString('filename="' . basename($file) . '"', $body);
        $this->assertStringContainsString("\x89PNG", $body);
        $this->assertSame('https://cdn.example.com/wa/profile.png', $profile['profilePhotoUrl']);
        $this->assertSame(self::SENDER, $profile['phoneNumber']);
    }

    public function testUploadProfilePhotoSendsAGivenContentType(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->profile())),
        ]);

        $client->whatsapp()->senders->uploadProfilePhoto(self::SENDER, $this->pngFile(), 'image/png');

        $this->assertStringContainsString('Content-Type: image/png', (string) $this->lastRequest()->getBody());
    }

    public function testUploadProfilePhotoValidatesPhone(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid phone number format');
        $client->whatsapp()->senders->uploadProfilePhoto('5555550147', $this->pngFile());
    }

    public function testUploadProfilePhotoRefusesAMissingFile(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('File not found');
        $client->whatsapp()->senders->uploadProfilePhoto(self::SENDER, '/nonexistent/photo.png');
    }

    public function testUploadProfilePhotoTooLargeThrowsAtOnce(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/senders/x/profile/photo', 413, [
                'error' => 'whatsapp_profile_photo_too_large',
                'message' => 'The photo must be 5 MB or smaller.',
            ]),
        ]);

        try {
            $client->whatsapp()->senders->uploadProfilePhoto(self::SENDER, $this->pngFile());
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(413, $e->getCode());
            $this->assertSame('whatsapp_profile_photo_too_large', $e->getApiErrorCode());
        }
        $this->assertCount(1, $this->history);
    }

    public function testUploadProfilePhotoNotJpegOrPngIsAValidationError(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/senders/x/profile/photo', 400, [
                'error' => 'whatsapp_profile_photo_invalid',
                'message' => 'The photo must be a JPEG or PNG image.',
            ]),
        ]);

        try {
            $client->whatsapp()->senders->uploadProfilePhoto(self::SENDER, $this->pngFile());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('whatsapp_profile_photo_invalid', $e->getApiErrorCode());
        }
    }

    public function testUploadProfilePhotoServerErrorIsNotRetried(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/senders/x/profile/photo', 502, [
                'error' => 'whatsapp_profile_update_failed',
                'message' => "The photo couldn't be uploaded. WhatsApp needs a square JPEG or PNG at least 192 pixels wide. Please try again shortly.",
            ]),
            new Response(200, [], json_encode($this->profile())),
        ]);
        $this->skipSleeps($client);

        try {
            $client->whatsapp()->senders->uploadProfilePhoto(self::SENDER, $this->pngFile());
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertSame('whatsapp_profile_update_failed', $e->getApiErrorCode());
        }
        $this->assertCount(1, $this->history);
    }

    public function testUploadProfilePhotoDroppedConnectionIsNotRetried(): void
    {
        $client = $this->createMockClient([
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', '/whatsapp/senders/x/profile/photo')),
            new Response(200, [], json_encode($this->profile())),
        ]);
        $this->skipSleeps($client);

        try {
            $client->whatsapp()->senders->uploadProfilePhoto(self::SENDER, $this->pngFile());
            $this->fail('Expected NetworkException');
        } catch (NetworkException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
        $this->assertCount(1, $this->history);
    }

    public function testUpdateProfileServerErrorIsStillRetried(): void
    {
        $client = $this->createMockClient([
            $this->apiError('PATCH', '/whatsapp/senders/x/profile', 503, ['error' => 'service_unavailable']),
            new Response(200, [], json_encode($this->profile())),
        ]);
        $this->skipSleeps($client);

        $client->whatsapp()->senders->updateProfile(self::SENDER, ['about' => 'Fresh roasted coffee, delivered.']);

        $this->assertCount(2, $this->history);
    }

    // ==================== senders->deleteProfilePhoto() ====================

    public function testDeleteProfilePhoto(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->profile(['profilePhotoUrl' => null]))),
        ]);

        $profile = $client->whatsapp()->senders->deleteProfilePhoto(self::SENDER);

        $this->assertSame('DELETE', $this->lastRequest()->getMethod());
        $this->assertSame(self::SENDER_PATH . '/profile/photo', $this->lastRequest()->getUri()->getPath());
        $this->assertNull($profile['profilePhotoUrl']);
        $this->assertSame('Acme Coffee', $profile['displayName']);
    }

    public function testDeleteProfilePhotoValidatesPhone(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $client->whatsapp()->senders->deleteProfilePhoto('not-a-number');
    }

    public function testDeleteProfilePhotoNotConnected(): void
    {
        $client = $this->createMockClient([
            $this->apiError('DELETE', '/whatsapp/senders/x/profile/photo', 404, [
                'error' => 'whatsapp_sender_not_connected',
                'message' => "This number isn't connected to WhatsApp yet.",
            ]),
        ]);

        $this->expectException(NotFoundException::class);
        $client->whatsapp()->senders->deleteProfilePhoto(self::SENDER);
    }

    // ==================== conversational components ====================

    public function testGetConversationalComponents(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'phoneNumber' => self::SENDER,
                'iceBreakers' => ['Where is my order?', 'Opening hours'],
                'commands' => [['command' => 'track', 'description' => 'Track an order']],
            ])),
        ]);

        $components = $client->whatsapp()->senders->getConversationalComponents(self::SENDER);

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame(self::SENDER_PATH . '/conversational_components', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['Where is my order?', 'Opening hours'], $components['iceBreakers']);
        $this->assertSame('track', $components['commands'][0]['command']);
        $this->assertSame('Track an order', $components['commands'][0]['description']);
    }

    public function testGetConversationalComponentsValidatesPhone(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $client->whatsapp()->senders->getConversationalComponents('5555550147');
    }

    public function testUpdateConversationalComponentsSendsBothLists(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'phoneNumber' => self::SENDER,
                'iceBreakers' => ['Where is my order?'],
                'commands' => [['command' => 'track', 'description' => 'Track an order']],
            ])),
        ]);

        $components = $client->whatsapp()->senders->updateConversationalComponents(self::SENDER, [
            'iceBreakers' => ['Where is my order?'],
            'commands' => [['command' => '/track', 'description' => 'Track an order']],
        ]);

        $this->assertSame('PATCH', $this->lastRequest()->getMethod());
        $this->assertSame(self::SENDER_PATH . '/conversational_components', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([
            'iceBreakers' => ['Where is my order?'],
            'commands' => [['command' => '/track', 'description' => 'Track an order']],
        ], $this->sentBody());
        $this->assertSame('track', $components['commands'][0]['command']);
    }

    public function testUpdateConversationalComponentsEmptyListClearsAndOmitsTheOther(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'phoneNumber' => self::SENDER,
                'iceBreakers' => [],
                'commands' => [['command' => 'track', 'description' => 'Track an order']],
            ])),
        ]);

        $components = $client->whatsapp()->senders->updateConversationalComponents(self::SENDER, [
            'iceBreakers' => [],
        ]);

        $this->assertSame('{"iceBreakers":[]}', (string) $this->lastRequest()->getBody());
        $this->assertSame([], $components['iceBreakers']);
    }

    public function testUpdateConversationalComponentsRequiresAList(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Provide iceBreakers, commands, or both');
        $client->whatsapp()->senders->updateConversationalComponents(self::SENDER, []);
    }

    public function testUpdateConversationalComponentsInvalidListIsAValidationError(): void
    {
        $client = $this->createMockClient([
            $this->apiError('PATCH', '/whatsapp/senders/x/conversational_components', 400, [
                'error' => 'invalid_request',
                'message' => 'At most 4 ice breakers are allowed.',
            ]),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('At most 4 ice breakers are allowed.');
        $client->whatsapp()->senders->updateConversationalComponents(self::SENDER, [
            'iceBreakers' => ['a', 'b', 'c', 'd', 'e'],
        ]);
    }

    // ==================== senders->setCalling() ====================

    public function testSetCalling(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'phoneNumber' => self::SENDER,
                'callingEnabled' => true,
                'outboundCallingAllowed' => false,
            ])),
        ]);

        $result = $client->whatsapp()->senders->setCalling(self::SENDER, true);

        $this->assertSame('PATCH', $this->lastRequest()->getMethod());
        $this->assertSame(self::SENDER_PATH . '/calling', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('{"enabled":true}', (string) $this->lastRequest()->getBody());
        $this->assertTrue($result['callingEnabled']);
        $this->assertFalse($result['outboundCallingAllowed']);
    }

    public function testSetCallingOffSendsFalse(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'phoneNumber' => self::SENDER,
                'callingEnabled' => false,
                'outboundCallingAllowed' => false,
            ])),
        ]);

        $client->whatsapp()->senders->setCalling(self::SENDER, false);

        $this->assertSame('{"enabled":false}', (string) $this->lastRequest()->getBody());
    }

    public function testSetCallingValidatesPhone(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $client->whatsapp()->senders->setCalling('5555550147', true);
    }

    public function testSetCallingWithoutVoiceIsAConflict(): void
    {
        $client = $this->createMockClient([
            $this->apiError('PATCH', '/whatsapp/senders/x/calling', 409, [
                'error' => 'voice_not_enabled',
                'message' => 'Turn on calls for this number first, in its voice settings, so WhatsApp calls have somewhere to ring.',
            ]),
        ]);

        try {
            $client->whatsapp()->senders->setCalling(self::SENDER, true);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame('voice_not_enabled', $e->getApiErrorCode());
        }
    }

    public function testSetCallingRefusedByWhatsAppIsAValidationError(): void
    {
        $client = $this->createMockClient([
            $this->apiError('PATCH', '/whatsapp/senders/x/calling', 422, [
                'error' => 'whatsapp_calling_unavailable',
                'message' => "WhatsApp didn't allow calling on this number.",
            ]),
        ]);

        try {
            $client->whatsapp()->senders->setCalling(self::SENDER, true);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('whatsapp_calling_unavailable', $e->getApiErrorCode());
        }
    }

    // ==================== senders->list() new fields ====================

    public function testSendersListCarriesAccountAndCallingFields(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'senders' => [
                    [
                        'phoneNumber' => self::SENDER,
                        'displayName' => 'Acme Coffee',
                        'status' => 'active',
                        'qualityRating' => 'GREEN',
                        'businessAccountId' => '102938475610293',
                        'businessName' => 'Acme Coffee LLC',
                        'callingEnabled' => true,
                        'outboundCallingAllowed' => false,
                        'createdAt' => '2026-09-30T09:00:00.000Z',
                    ],
                    [
                        'phoneNumber' => '+445555550123',
                        'displayName' => null,
                        'status' => 'pending',
                        'qualityRating' => null,
                        'businessAccountId' => null,
                        'businessName' => null,
                        'callingEnabled' => false,
                        'outboundCallingAllowed' => true,
                        'createdAt' => '2026-10-01T09:00:00.000Z',
                    ],
                ],
            ])),
        ]);

        $senders = $client->whatsapp()->senders->list()['senders'];

        $this->assertSame('102938475610293', $senders[0]['businessAccountId']);
        $this->assertSame('Acme Coffee LLC', $senders[0]['businessName']);
        $this->assertTrue($senders[0]['callingEnabled']);
        $this->assertFalse($senders[0]['outboundCallingAllowed']);
        $this->assertNull($senders[1]['businessAccountId']);
        $this->assertNull($senders[1]['businessName']);
        $this->assertTrue($senders[1]['outboundCallingAllowed']);
    }

    // ==================== signup: add a number by code ====================

    public function testSignupCreateAddsANumberToAConnectedAccount(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->verifyingSignup(['verificationMethod' => 'voice']))),
        ]);

        $signup = $client->whatsapp()->signup->create(self::SENDER, [
            'businessAccountId' => '102938475610293',
            'verificationMethod' => 'voice',
            'displayName' => 'Acme Coffee',
        ]);

        $this->assertSame('/api/v1/whatsapp/signup', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([
            'phoneNumber' => self::SENDER,
            'businessAccountId' => '102938475610293',
            'verificationMethod' => 'voice',
            'displayName' => 'Acme Coffee',
        ], $this->sentBody());
        $this->assertSame('verifying', $signup['status']);
        $this->assertArrayNotHasKey('connectUrl', $signup);
        $this->assertSame('voice', $signup['verificationMethod']);
        $this->assertSame(5, $signup['verificationAttemptsRemaining']);
    }

    public function testSignupCreateSendsOnlyTheOptionsGiven(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->verifyingSignup())),
        ]);

        $client->whatsapp()->signup->create(self::SENDER, ['businessAccountId' => '102938475610293']);

        $this->assertSame([
            'phoneNumber' => self::SENDER,
            'businessAccountId' => '102938475610293',
        ], $this->sentBody());
    }

    public function testSignupCreateRefusesABlankBusinessAccountId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('businessAccountId must be a non-empty string');
        $client->whatsapp()->signup->create(self::SENDER, ['businessAccountId' => '']);
    }

    public function testSignupCreateRefusesAWhitespaceOnlyBusinessAccountId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('businessAccountId must be a non-empty string');
        $client->whatsapp()->signup->create(self::SENDER, ['businessAccountId' => '   ']);
    }

    public function testSignupCreateWithoutOptionsKeepsTheFacebookBody(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode([
                'id' => 'was_1',
                'connectUrl' => 'https://sendly.live/whatsapp/connect?token=tok_abc',
                'status' => 'initiated',
            ])),
        ]);

        $signup = $client->whatsapp()->signup->create(self::SENDER, []);

        $this->assertSame(['phoneNumber' => self::SENDER], $this->sentBody());
        $this->assertSame('initiated', $signup['status']);
    }

    public function testSignupCreateUnknownAccountIsNotFound(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/signup', 404, [
                'error' => 'whatsapp_business_account_not_found',
                'message' => "There's no connected WhatsApp Business account with this id in your workspace.",
            ]),
        ]);

        try {
            $client->whatsapp()->signup->create(self::SENDER, ['businessAccountId' => '999']);
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('whatsapp_business_account_not_found', $e->getApiErrorCode());
        }
        $this->assertSame('999', $this->sentBody()['businessAccountId']);
    }

    public function testSignupCreateVerificationStartUnreachableIsNotRetried(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/signup', 502, [
                'error' => 'whatsapp_verification_start_failed',
                'message' => "WhatsApp couldn't start verifying this number. Any setup fee is refunded automatically. Please try again shortly.",
            ]),
            new Response(201, [], json_encode($this->verifyingSignup())),
        ]);
        $this->skipSleeps($client);

        try {
            $client->whatsapp()->signup->create(self::SENDER, ['businessAccountId' => '104996582519384']);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertSame('whatsapp_verification_start_failed', $e->getApiErrorCode());
        }
        $this->assertCount(1, $this->history, 'each retry would start, charge and refund another signup');
    }

    public function testSignupCreateByCodeServerErrorIsNotRetried(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/signup', 500, [
                'error' => 'internal_error',
                'message' => 'Something went wrong asking WhatsApp for the code. Any setup fee is refunded automatically. Please try again.',
            ]),
            new Response(201, [], json_encode($this->verifyingSignup())),
        ]);
        $this->skipSleeps($client);

        try {
            $client->whatsapp()->signup->create(self::SENDER, ['businessAccountId' => '104996582519384']);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(500, $e->getCode());
        }
        $this->assertCount(1, $this->history);
    }

    public function testSignupCreateByCodeDroppedConnectionIsNotRetried(): void
    {
        $client = $this->createMockClient([
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', '/whatsapp/signup')),
            new Response(201, [], json_encode($this->verifyingSignup())),
        ]);
        $this->skipSleeps($client);

        $this->expectException(NetworkException::class);
        try {
            $client->whatsapp()->signup->create(self::SENDER, ['businessAccountId' => '104996582519384']);
        } finally {
            $this->assertCount(1, $this->history);
        }
    }

    public function testSignupCreateFacebookFlowServerErrorIsStillRetried(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/signup', 503, [
                'error' => 'whatsapp_unavailable',
                'message' => "WhatsApp connections are temporarily unavailable. You haven't been charged. Please try again later.",
                'retryAfter' => 3600,
            ], ['Retry-After' => '3600']),
            new Response(201, [], json_encode([
                'id' => 'was_1',
                'connectUrl' => 'https://sendly.live/whatsapp/connect?token=tok_abc',
                'status' => 'initiated',
            ])),
        ]);
        $this->skipSleeps($client);

        $signup = $client->whatsapp()->signup->create(self::SENDER);

        $this->assertSame('initiated', $signup['status']);
        $this->assertCount(2, $this->history);
    }

    public function testSignupCreateVerificationStartRefusedIsAValidationError(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/signup', 422, [
                'error' => 'whatsapp_verification_start_failed',
                'message' => 'WhatsApp refused to verify this number. Any setup fee is refunded automatically.',
            ]),
        ]);

        try {
            $client->whatsapp()->signup->create(self::SENDER, ['businessAccountId' => '104996582519384']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('whatsapp_verification_start_failed', $e->getApiErrorCode());
        }
        $this->assertCount(1, $this->history);
    }

    public function testSignupCreateFacebookFlowWhileVerifyingCarriesTheSessionId(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/signup', 409, [
                'error' => 'whatsapp_verification_in_progress',
                'message' => 'This number is already being added to a connected WhatsApp Business account.',
                'id' => 'was_add_1',
            ]),
        ]);

        try {
            $client->whatsapp()->signup->create(self::SENDER);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame('whatsapp_verification_in_progress', $e->getApiErrorCode());
            $this->assertSame('was_add_1', $e->getResponseBody()['id']);
        }
    }

    public function testSignupGetWhileVerifyingCarriesTheCode(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->verifyingSignup([
                'verificationAttemptsRemaining' => 4,
                'verificationCode' => '481516',
            ]))),
        ]);

        $signup = $client->whatsapp()->signup->get('was_add_1');

        $this->assertSame('verifying', $signup['status']);
        $this->assertSame('102938475610293', $signup['businessAccountId']);
        $this->assertSame('sms', $signup['verificationMethod']);
        $this->assertSame(4, $signup['verificationAttemptsRemaining']);
        $this->assertSame('481516', $signup['verificationCode']);
    }

    // ==================== signup->verify() ====================

    public function testSignupVerify(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'id' => 'was_add_1',
                'status' => 'active',
                'phoneNumber' => self::SENDER,
                'businessAccountId' => '102938475610293',
                'failureReasons' => null,
                'updatedAt' => '2026-10-01T09:02:00.000Z',
            ])),
        ]);

        $signup = $client->whatsapp()->signup->verify('was_add_1', '481 516');

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/whatsapp/signup/was_add_1/verify', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['code' => '481 516'], $this->sentBody());
        $this->assertSame('active', $signup['status']);
    }

    public function testSignupVerifyRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Signup ID is required');
        $client->whatsapp()->signup->verify('', '481516');
    }

    public function testSignupVerifyWrongCodeReportsAttemptsRemaining(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/signup/was_add_1/verify', 422, [
                'error' => 'whatsapp_verification_code_invalid',
                'message' => "That code wasn't accepted. Check it, or request a new one.",
                'attemptsRemaining' => 3,
            ]),
        ]);

        try {
            $client->whatsapp()->signup->verify('was_add_1', '000000');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('whatsapp_verification_code_invalid', $e->getApiErrorCode());
            $this->assertSame(3, $e->getResponseBody()['attemptsRemaining']);
        }
        $this->assertCount(1, $this->history);
    }

    public function testSignupVerifyTooManyWrongCodesIsAConflict(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/signup/was_add_1/verify', 409, [
                'error' => 'whatsapp_verification_failed',
                'message' => 'Too many wrong codes. Any setup fee is refunded automatically. Start again to retry.',
            ]),
        ]);

        try {
            $client->whatsapp()->signup->verify('was_add_1', '000000');
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame('whatsapp_verification_failed', $e->getApiErrorCode());
        }
    }

    public function testSignupVerifyActivationPendingIsNotRetried(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/signup/was_add_1/verify', 502, [
                'error' => 'whatsapp_activation_pending',
                'message' => "WhatsApp accepted the code, but we couldn't finish connecting the number. Our team has been alerted; check back shortly.",
            ]),
            new Response(200, [], json_encode($this->verifyingSignup(['status' => 'active']))),
        ]);
        $this->skipSleeps($client);

        try {
            $client->whatsapp()->signup->verify('was_add_1', '481516');
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(502, $e->getCode());
            $this->assertSame('whatsapp_activation_pending', $e->getApiErrorCode());
        }
        $this->assertCount(1, $this->history);
    }

    public function testSignupVerifyUnavailableIsNotRetried(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/signup/was_add_1/verify', 502, [
                'error' => 'whatsapp_verification_unavailable',
                'message' => "WhatsApp couldn't check the code right now. Please try again shortly.",
            ]),
            new Response(200, [], json_encode($this->verifyingSignup(['status' => 'active']))),
        ]);
        $this->skipSleeps($client);

        try {
            $client->whatsapp()->signup->verify('was_add_1', '481516');
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame('whatsapp_verification_unavailable', $e->getApiErrorCode());
        }
        $this->assertCount(1, $this->history);
    }

    public function testSignupVerifyDroppedConnectionIsNotRetried(): void
    {
        $client = $this->createMockClient([
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', '/whatsapp/signup/was_add_1/verify')),
            new Response(200, [], json_encode($this->verifyingSignup(['status' => 'active']))),
        ]);
        $this->skipSleeps($client);

        $this->expectException(NetworkException::class);
        try {
            $client->whatsapp()->signup->verify('was_add_1', '481516');
        } finally {
            $this->assertCount(1, $this->history);
        }
    }

    // ==================== signup->resend() ====================

    public function testSignupResendWithAMethod(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->verifyingSignup(['verificationMethod' => 'voice']))),
        ]);

        $signup = $client->whatsapp()->signup->resend('was_add_1', 'voice');

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/whatsapp/signup/was_add_1/resend', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['verificationMethod' => 'voice'], $this->sentBody());
        $this->assertSame('voice', $signup['verificationMethod']);
    }

    public function testSignupResendWithoutAMethodSendsNone(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->verifyingSignup())),
        ]);

        $client->whatsapp()->signup->resend('was_add_1');

        $this->assertSame([], $this->sentBody());
    }

    public function testSignupResendRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Signup ID is required');
        $client->whatsapp()->signup->resend('');
    }

    public function testSignupResendTooSoonIsARateLimit(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/signup/was_add_1/resend', 429, [
                'error' => 'whatsapp_verification_resend_too_soon',
                'message' => 'Wait 24 seconds before requesting another code.',
                'retryAfter' => 24,
            ], ['Retry-After' => '24']),
        ]);

        try {
            $client->whatsapp()->signup->resend('was_add_1');
            $this->fail('Expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame('whatsapp_verification_resend_too_soon', $e->getApiErrorCode());
            $this->assertSame(24, $e->getRetryAfter());
        }
        $this->assertCount(1, $this->history);
    }

    public function testSignupResendServerErrorIsRetried(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/whatsapp/signup/was_add_1/resend', 502, [
                'error' => 'whatsapp_verification_resend_failed',
                'message' => "WhatsApp couldn't send another code right now. Please try again shortly.",
            ]),
            new Response(200, [], json_encode($this->verifyingSignup())),
        ]);
        $this->skipSleeps($client);

        $signup = $client->whatsapp()->signup->resend('was_add_1');

        $this->assertSame('verifying', $signup['status']);
        $this->assertCount(2, $this->history);
    }

    // ==================== sends: 409 whatsapp_send_unconfirmed ====================

    public function testSendUnconfirmedThrowsAtOnceWithoutARetry(): void
    {
        $client = $this->createMockClient([
            $this->apiError('POST', '/messages', 409, [
                'error' => 'whatsapp_send_unconfirmed',
                'errorCode' => 'E024',
                'message' => "We couldn't confirm whether WhatsApp accepted this message. It has been marked failed and refunded, but it may still be delivered. Check before sending it again, or it could arrive twice.",
            ]),
        ]);

        try {
            $client->messages()->send([
                'channel' => 'whatsapp',
                'to' => '+15555550123',
                'from' => self::SENDER,
                'text' => 'Your table is ready!',
            ]);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertNotInstanceOf(ValidationException::class, $e);
            $this->assertSame(409, $e->getCode());
            $this->assertSame('whatsapp_send_unconfirmed', $e->getApiErrorCode());
            $this->assertSame('E024', $e->getResponseBody()['errorCode']);
        }
        $this->assertCount(1, $this->history, 'an unconfirmed send must not be retried');
    }
}
