<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Sendly\Sendly;
use Sendly\Resources\Rcs;
use Sendly\Resources\RcsAgents;
use Sendly\Resources\RcsBrands;
use Sendly\Resources\RcsDossier;
use Sendly\Resources\RcsRegistration;
use Sendly\Resources\RcsCustomerStage;
use Sendly\Resources\RcsErrorCode;
use Sendly\Resources\RcsReviewStatus;
use Sendly\Exceptions\SendlyException;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\NotFoundException;
use ReflectionClass;

/**
 * Tests for the RCS registration surface: registration->get(),
 * dossier->get(), brands->create()/update(), agents->create()/get()/
 * update()/setTestDevices()/submit()/requestLaunch()
 */
class RcsRegistrationTest extends TestCase
{
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

    private function darkResponse(string $method, string $path): RequestException
    {
        return new RequestException(
            'Not Found',
            new Request($method, $path),
            new Response(404, [], json_encode([
                'error' => 'rcs_not_enabled',
                'message' => "RCS registration isn't enabled for this account yet.",
            ]))
        );
    }

    private function brand(array $overrides = []): array
    {
        return array_merge([
            'id' => 'rcb_1',
            'reviewStatus' => 'draft',
            'customerStage' => 'draft',
            'displayName' => 'Acme Coffee',
            'legalName' => 'Acme Holdings LLC',
            'legalEntityType' => 'LIMITED_LIABILITY_COMPANY',
            'organizationType' => 'PRIVATE_PROFIT',
            'stockSymbol' => null,
            'websiteUrl' => 'https://acme.example',
            'ein' => '123456789',
            'address' => [
                'line1' => '1 Market St',
                'line2' => null,
                'city' => 'San Francisco',
                'state' => 'CA',
                'postalCode' => '94105',
                'countryCode' => 'US',
            ],
            'contact' => [
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'title' => null,
                'email' => 'jane@acme.example',
                'phoneNumber' => '+15551234567',
            ],
            'reviewNote' => null,
            'rejectionReason' => null,
            'submittedForReviewAt' => null,
            'sentToCarrierAt' => null,
            'verifiedAt' => null,
            'createdAt' => '2026-09-01T09:00:00Z',
            'updatedAt' => '2026-09-01T09:00:00Z',
        ], $overrides);
    }

    private function device(array $overrides = []): array
    {
        return array_merge([
            'id' => 'rcd_1',
            'phoneNumber' => '+15551234567',
            'label' => 'QA phone',
            'inviteStatus' => null,
            'createdAt' => '2026-09-01T09:30:00Z',
        ], $overrides);
    }

    private function agent(array $overrides = []): array
    {
        return array_merge([
            'id' => 'rca_1',
            'brandId' => 'rcb_1',
            'status' => 'draft',
            'reviewStatus' => 'draft',
            'customerStage' => 'draft',
            'displayName' => 'Acme Coffee',
            'useCase' => 'MULTI_USE',
            'hostingRegion' => null,
            'basics' => [
                'displayName' => 'Acme Coffee',
                'useCase' => 'MULTI_USE',
                'hostingRegion' => null,
                'description' => 'Order updates from Acme Coffee.',
                'logoUrl' => 'https://acme.example/rcs/logo.png',
                'heroUrl' => 'https://acme.example/rcs/hero.png',
                'brandColor' => '#6B4F3A',
                'privacyPolicyUrl' => 'https://acme.example/privacy',
                'termsAndConditionsUrl' => 'https://acme.example/terms',
                'phoneNumber' => ['number' => '+15551234567', 'label' => 'Call us'],
            ],
            'campaign' => null,
            'testing' => null,
            'reviewNote' => null,
            'rejectionReason' => null,
            'testDevices' => [],
            'submittedForReviewAt' => null,
            'basicsSubmittedAt' => null,
            'launchSubmittedAt' => null,
            'liveAt' => null,
            'createdAt' => '2026-09-01T09:05:00Z',
            'updatedAt' => '2026-09-01T09:05:00Z',
        ], $overrides);
    }

    // ==================== resource registration ====================

    public function testRegistrationSubResourcesAreRegistered(): void
    {
        $client = new Sendly('test_api_key');
        $this->assertInstanceOf(Rcs::class, $client->rcs);
        $this->assertInstanceOf(RcsRegistration::class, $client->rcs->registration);
        $this->assertInstanceOf(RcsDossier::class, $client->rcs->dossier);
        $this->assertInstanceOf(RcsBrands::class, $client->rcs->brands);
        $this->assertInstanceOf(RcsAgents::class, $client->rcs->agents);
        $this->assertSame($client->rcs->brands, $client->rcs()->brands);
    }

    public function testStageAndErrorCodeConstants(): void
    {
        $this->assertSame('draft', RcsCustomerStage::DRAFT);
        $this->assertSame('in_review', RcsCustomerStage::IN_REVIEW);
        $this->assertSame('launch_review', RcsCustomerStage::LAUNCH_REVIEW);
        $this->assertSame('live', RcsCustomerStage::LIVE);
        $this->assertSame('awaiting_review', RcsReviewStatus::AWAITING_REVIEW);
        $this->assertSame('launch_requested', RcsReviewStatus::LAUNCH_REQUESTED);
        $this->assertSame('rcs_not_enabled', RcsErrorCode::NOT_ENABLED);
        $this->assertSame('rcs_field_locked', RcsErrorCode::FIELD_LOCKED);
        $this->assertSame('rcs_invalid_content', RcsErrorCode::INVALID_CONTENT);
    }

    // ==================== registration->get() ====================

    public function testRegistrationGet(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'brand' => $this->brand(['reviewStatus' => 'awaiting_review', 'customerStage' => 'in_review']),
                'agent' => $this->agent([
                    'reviewStatus' => 'awaiting_review',
                    'customerStage' => 'in_review',
                    'testDevices' => [$this->device()],
                    'submittedForReviewAt' => '2026-09-01T10:00:00Z',
                ]),
                'devices' => [$this->device()],
                'stage' => 'in_review',
                'usEligible' => true,
            ])),
        ]);

        $result = $client->rcs()->registration->get();

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/rcs/registration', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('rcb_1', $result['brand']['id']);
        $this->assertSame('awaiting_review', $result['brand']['reviewStatus']);
        $this->assertSame('rca_1', $result['agent']['id']);
        $this->assertSame('2026-09-01T10:00:00Z', $result['agent']['submittedForReviewAt']);
        $this->assertCount(1, $result['devices']);
        $this->assertSame('+15551234567', $result['devices'][0]['phoneNumber']);
        $this->assertNull($result['devices'][0]['inviteStatus']);
        $this->assertSame(RcsCustomerStage::IN_REVIEW, $result['stage']);
        $this->assertTrue($result['usEligible']);
        $this->assertArrayNotHasKey('enabled', $result);
    }

    public function testRegistrationGetEmptyWorkspace(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'brand' => null,
                'agent' => null,
                'devices' => [],
                'stage' => 'draft',
                'usEligible' => true,
            ])),
        ]);

        $result = $client->rcs()->registration->get();

        $this->assertNull($result['brand']);
        $this->assertNull($result['agent']);
        $this->assertSame([], $result['devices']);
        $this->assertSame(RcsCustomerStage::DRAFT, $result['stage']);
    }

    // ==================== dossier->get() ====================

    public function testDossierGet(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'brand' => [
                    'legalName' => 'Acme Holdings LLC',
                    'displayName' => 'Acme Coffee',
                    'ein' => '123456789',
                    'organizationType' => 'PRIVATE_PROFIT',
                    'websiteUrl' => 'https://acme.example',
                    'address' => ['line1' => '1 Market St', 'city' => 'San Francisco', 'state' => 'CA', 'postalCode' => '94105', 'countryCode' => 'US'],
                    'contact' => ['firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'jane@acme.example', 'phoneNumber' => '+15551234567'],
                ],
                'usEligible' => true,
                'source' => 'tendlc',
            ])),
        ]);

        $result = $client->rcs()->dossier->get();

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/rcs/dossier', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('tendlc', $result['source']);
        $this->assertSame('Acme Holdings LLC', $result['brand']['legalName']);
        $this->assertSame('US', $result['brand']['address']['countryCode']);
        $this->assertArrayNotHasKey('profileId', $result['brand']);
        $this->assertTrue($result['usEligible']);
    }

    public function testDossierGetNothingOnFile(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['brand' => [], 'usEligible' => true, 'source' => 'none'])),
        ]);

        $result = $client->rcs()->dossier->get();

        $this->assertSame('none', $result['source']);
        $this->assertSame([], $result['brand']);
    }

    // ==================== brands->create() ====================

    public function testBrandsCreate(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode(['brand' => $this->brand()])),
        ]);

        $result = $client->rcs()->brands->create([
            'displayName' => 'Acme Coffee',
            'legalName' => 'Acme Holdings LLC',
            'legalEntityType' => 'LIMITED_LIABILITY_COMPANY',
            'organizationType' => 'PRIVATE_PROFIT',
            'websiteUrl' => 'https://acme.example',
            'ein' => '12-3456789',
            'stockSymbol' => null,
            'address' => [
                'line1' => '1 Market St',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postalCode' => '94105',
                'countryCode' => 'US',
                'geo' => 'stripped',
            ],
            'contact' => [
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'email' => 'jane@acme.example',
                'phoneNumber' => '+15551234567',
            ],
            'profileId' => 'mp_stripped',
            'reviewStatus' => 'approved_for_carrier',
        ]);

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/rcs/brands', $this->lastRequest()->getUri()->getPath());
        $this->assertTrue($this->lastRequest()->hasHeader('Idempotency-Key'));

        $sent = $this->sentBody();
        $this->assertSame('Acme Coffee', $sent['displayName']);
        $this->assertSame('12-3456789', $sent['ein']);
        $this->assertArrayHasKey('stockSymbol', $sent);
        $this->assertNull($sent['stockSymbol']);
        $this->assertSame('US', $sent['address']['countryCode']);
        $this->assertArrayNotHasKey('geo', $sent['address']);
        $this->assertSame('jane@acme.example', $sent['contact']['email']);
        $this->assertArrayNotHasKey('profileId', $sent);
        $this->assertArrayNotHasKey('reviewStatus', $sent);

        $this->assertSame('rcb_1', $result['brand']['id']);
        $this->assertSame(RcsReviewStatus::DRAFT, $result['brand']['reviewStatus']);
        $this->assertSame(RcsCustomerStage::DRAFT, $result['brand']['customerStage']);
        $this->assertArrayNotHasKey('profileId', $result['brand']);
    }

    public function testBrandsCreateHonoursCallerIdempotencyKey(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode(['brand' => $this->brand()])),
        ]);

        $client->rcs()->brands->create(['legalName' => 'Acme Holdings LLC'], 'acme-rcs-brand-1');

        $this->assertSame('acme-rcs-brand-1', $this->lastRequest()->getHeaderLine('Idempotency-Key'));
        $this->assertSame(['legalName' => 'Acme Holdings LLC'], $this->sentBody());
    }

    public function testBrandsCreateEmptyDraft(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode(['brand' => $this->brand(['legalName' => '', 'ein' => ''])])),
        ]);

        $result = $client->rcs()->brands->create();

        $this->assertSame([], $this->sentBody());
        $this->assertSame('', $result['brand']['legalName']);
    }

    public function testBrandsCreateUsOnly(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unprocessable Entity',
                new Request('POST', '/rcs/brands'),
                new Response(422, [], json_encode([
                    'error' => 'rcs_us_only',
                    'message' => 'RCS registration is available to US businesses for now.',
                ]))
            ),
        ]);

        try {
            $client->rcs()->brands->create(['address' => ['countryCode' => 'GB']]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('RCS registration is available to US businesses for now.', $e->getMessage());
            $this->assertSame(RcsErrorCode::US_ONLY, $e->getApiErrorCode());
        }
    }

    // ==================== brands->update() ====================

    public function testBrandsUpdate(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['brand' => $this->brand(['displayName' => 'Acme Coffee Co', 'contact' => null])])),
        ]);

        $result = $client->rcs()->brands->update('rcb_1', [
            'displayName' => 'Acme Coffee Co',
            'address' => ['line2' => null],
            'contact' => null,
            'profileId' => 'mp_stripped',
        ]);

        $this->assertSame('PATCH', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/rcs/brands/rcb_1', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([
            'displayName' => 'Acme Coffee Co',
            'address' => ['line2' => null],
            'contact' => null,
        ], $this->sentBody());
        $this->assertSame('Acme Coffee Co', $result['brand']['displayName']);
    }

    public function testBrandsUpdateRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Brand ID is required');
        $client->rcs()->brands->update('', ['displayName' => 'Acme']);
    }

    public function testBrandsUpdateLockedDuringReview(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Conflict',
                new Request('PATCH', '/rcs/brands/rcb_1'),
                new Response(409, [], json_encode([
                    'error' => 'rcs_field_locked',
                    'message' => 'This registration is being reviewed; we will email you if changes are needed.',
                ]))
            ),
        ], ['maxRetries' => 0]);

        try {
            $client->rcs()->brands->update('rcb_1', ['displayName' => 'Acme']);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame(RcsErrorCode::FIELD_LOCKED, $e->getApiErrorCode());
            $this->assertStringContainsString('being reviewed', $e->getMessage());
        }
    }

    // ==================== agents->create() ====================

    public function testAgentsCreate(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode(['agent' => $this->agent()])),
        ]);

        $result = $client->rcs()->agents->create([
            'brandId' => 'rcb_1',
            'displayName' => 'Acme Coffee',
            'useCase' => 'MULTI_USE',
            'basics' => [
                'description' => 'Order updates from Acme Coffee.',
                'logoUrl' => 'https://acme.example/rcs/logo.png',
                'heroUrl' => 'https://acme.example/rcs/hero.png',
                'brandColor' => '#6B4F3A',
                'privacyPolicyUrl' => 'https://acme.example/privacy',
                'termsAndConditionsUrl' => 'https://acme.example/terms',
                'phoneNumber' => ['number' => '+15551234567', 'label' => 'Call us', 'extension' => 'stripped'],
                'website' => ['url' => 'https://acme.example', 'label' => 'Visit us'],
                'email' => null,
                'profileId' => 'mp_stripped',
                'hostingRegion' => 'stripped',
            ],
            'campaign' => [
                'agentOverview' => 'Order confirmations and pickup alerts.',
                'interactions' => [
                    ['interactionType' => 'TRANSACTIONAL_UPDATES', 'description' => 'Order status'],
                ],
                'messageExamples' => ['Your order is ready.', 'Thanks for your order!', 'Reply STOP to opt out.'],
                'consentSettings' => [
                    'optInMethods' => [['methodType' => 'WEBSITE', 'description' => 'Checkout checkbox']],
                    'doubleOptIn' => true,
                    'optInMessage' => 'You are opted in.',
                    'secret' => 'stripped',
                ],
            ],
            'testing' => ['testUrl' => 'https://acme.example/rcs-test'],
            'messagingProfileId' => 'stripped',
        ]);

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/rcs/agents', $this->lastRequest()->getUri()->getPath());
        $this->assertTrue($this->lastRequest()->hasHeader('Idempotency-Key'));

        $sent = $this->sentBody();
        $this->assertSame('rcb_1', $sent['brandId']);
        $this->assertSame('Acme Coffee', $sent['displayName']);
        $this->assertSame('MULTI_USE', $sent['useCase']);
        $this->assertSame('https://acme.example/rcs/logo.png', $sent['basics']['logoUrl']);
        $this->assertSame(['number' => '+15551234567', 'label' => 'Call us'], $sent['basics']['phoneNumber']);
        $this->assertSame('Visit us', $sent['basics']['website']['label']);
        $this->assertArrayHasKey('email', $sent['basics']);
        $this->assertNull($sent['basics']['email']);
        $this->assertArrayNotHasKey('profileId', $sent['basics']);
        $this->assertArrayNotHasKey('hostingRegion', $sent['basics']);
        $this->assertSame('TRANSACTIONAL_UPDATES', $sent['campaign']['interactions'][0]['interactionType']);
        $this->assertCount(3, $sent['campaign']['messageExamples']);
        $this->assertSame('WEBSITE', $sent['campaign']['consentSettings']['optInMethods'][0]['methodType']);
        $this->assertTrue($sent['campaign']['consentSettings']['doubleOptIn']);
        $this->assertArrayNotHasKey('secret', $sent['campaign']['consentSettings']);
        $this->assertSame(['testUrl' => 'https://acme.example/rcs-test'], $sent['testing']);
        $this->assertArrayNotHasKey('messagingProfileId', $sent);

        $this->assertSame('rca_1', $result['agent']['id']);
        $this->assertSame('rcb_1', $result['agent']['brandId']);
        $this->assertSame('draft', $result['agent']['status']);
        $this->assertSame(RcsReviewStatus::DRAFT, $result['agent']['reviewStatus']);
        $this->assertSame([], $result['agent']['testDevices']);
        $this->assertArrayNotHasKey('carrierAgentId', $result['agent']);
    }

    public function testAgentsCreateRequiresBrandId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('brandId is required');
        $client->rcs()->agents->create(['displayName' => 'Acme Coffee']);
    }

    public function testAgentsCreateRejectsNonHttpsMedia(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unprocessable Entity',
                new Request('POST', '/rcs/agents'),
                new Response(422, [], json_encode([
                    'error' => 'rcs_invalid_content',
                    'message' => "Assets can't be uploaded over the API. Logo, hero, and call-to-action media must be public https:// URLs.",
                    'errors' => [['path' => 'basics.logoUrl', 'message' => 'Must be a public https:// URL']],
                ]))
            ),
        ]);

        try {
            $client->rcs()->agents->create(['brandId' => 'rcb_1', 'basics' => ['logoUrl' => 'file:///logo.png']]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('public https:// URLs', $e->getMessage());
            $this->assertSame(RcsErrorCode::INVALID_CONTENT, $e->getApiErrorCode());
            $this->assertSame([['path' => 'basics.logoUrl', 'message' => 'Must be a public https:// URL']], $e->getDetails());
        }
    }

    // ==================== agents->get() ====================

    public function testAgentsGet(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'agent' => $this->agent([
                    'status' => 'testing',
                    'reviewStatus' => 'approved_for_carrier',
                    'customerStage' => 'testing',
                    'testDevices' => [$this->device(['inviteStatus' => 'PENDING'])],
                ]),
                'devices' => [$this->device(['inviteStatus' => 'PENDING'])],
                'stage' => 'testing',
            ])),
        ]);

        $result = $client->rcs()->agents->get('rca_1');

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/rcs/agents/rca_1', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('rca_1', $result['agent']['id']);
        $this->assertSame('testing', $result['agent']['status']);
        $this->assertSame(RcsReviewStatus::APPROVED_FOR_CARRIER, $result['agent']['reviewStatus']);
        $this->assertSame('PENDING', $result['devices'][0]['inviteStatus']);
        $this->assertSame(RcsCustomerStage::TESTING, $result['stage']);
    }

    public function testAgentsGetRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Agent ID is required');
        $client->rcs()->agents->get('');
    }

    public function testAgentsGetNotFound(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Not Found',
                new Request('GET', '/rcs/agents/rca_missing'),
                new Response(404, [], json_encode(['error' => 'rcs_not_found', 'message' => 'No agent with that id.']))
            ),
        ]);

        try {
            $client->rcs()->agents->get('rca_missing');
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(RcsErrorCode::NOT_FOUND, $e->getApiErrorCode());
        }
    }

    // ==================== agents->update() ====================

    public function testAgentsUpdate(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['agent' => $this->agent(['displayName' => 'Acme Coffee Co'])])),
        ]);

        $result = $client->rcs()->agents->update('rca_1', [
            'displayName' => 'Acme Coffee Co',
            'basics' => ['description' => 'Updated description', 'website' => null],
            'campaign' => null,
            'brandId' => 'rcb_other',
        ]);

        $this->assertSame('PATCH', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/rcs/agents/rca_1', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([
            'displayName' => 'Acme Coffee Co',
            'basics' => ['description' => 'Updated description', 'website' => null],
            'campaign' => null,
        ], $this->sentBody());
        $this->assertSame('Acme Coffee Co', $result['agent']['displayName']);
    }

    public function testAgentsUpdateRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Agent ID is required');
        $client->rcs()->agents->update('', ['displayName' => 'Acme']);
    }

    public function testAgentsUpdateLockedDuringReview(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Conflict',
                new Request('PATCH', '/rcs/agents/rca_1'),
                new Response(409, [], json_encode([
                    'error' => 'rcs_field_locked',
                    'message' => 'This registration is being reviewed; we will email you if changes are needed.',
                ]))
            ),
        ], ['maxRetries' => 0]);

        try {
            $client->rcs()->agents->update('rca_1', ['displayName' => 'Acme']);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame(RcsErrorCode::FIELD_LOCKED, $e->getApiErrorCode());
        }
    }

    // ==================== agents->setTestDevices() ====================

    public function testAgentsSetTestDevices(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['devices' => [
                $this->device(['label' => null]),
                $this->device(['id' => 'rcd_2', 'phoneNumber' => '+15559876543', 'label' => 'QA phone']),
            ]])),
        ]);

        $result = $client->rcs()->agents->setTestDevices('rca_1', [
            '+15551234567',
            ['phoneNumber' => '+15559876543', 'label' => 'QA phone', 'inviteStatus' => 'stripped'],
        ]);

        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/rcs/agents/rca_1/test-devices', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['devices' => [
            ['phoneNumber' => '+15551234567'],
            ['phoneNumber' => '+15559876543', 'label' => 'QA phone'],
        ]], $this->sentBody());
        $this->assertCount(2, $result['devices']);
        $this->assertSame('rcd_2', $result['devices'][1]['id']);
        $this->assertSame('QA phone', $result['devices'][1]['label']);
    }

    public function testAgentsSetTestDevicesEmptyListRemovesAll(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['devices' => []])),
        ]);

        $result = $client->rcs()->agents->setTestDevices('rca_1', []);

        $this->assertSame(['devices' => []], $this->sentBody());
        $this->assertSame([], $result['devices']);
    }

    public function testAgentsSetTestDevicesRejectsMalformedEntry(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Each test device must be');
        $client->rcs()->agents->setTestDevices('rca_1', [15551234567]);
    }

    public function testAgentsSetTestDevicesTooMany(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unprocessable Entity',
                new Request('PUT', '/rcs/agents/rca_1/test-devices'),
                new Response(422, [], json_encode([
                    'error' => 'rcs_invalid_content',
                    'message' => 'You can invite up to 20 test devices',
                    'errors' => [['path' => 'devices', 'message' => 'You can invite up to 20 test devices']],
                ]))
            ),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('up to 20 test devices');
        $client->rcs()->agents->setTestDevices('rca_1', array_fill(0, 21, '+15551234567'));
    }

    // ==================== agents->submit() ====================

    public function testAgentsSubmit(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'agent' => $this->agent([
                    'reviewStatus' => 'awaiting_review',
                    'customerStage' => 'in_review',
                    'submittedForReviewAt' => '2026-09-01T10:00:00Z',
                ]),
                'stage' => 'in_review',
            ])),
        ]);

        $result = $client->rcs()->agents->submit('rca_1');

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/rcs/agents/rca_1/submit', $this->lastRequest()->getUri()->getPath());
        $this->assertSame([], $this->sentBody());
        $this->assertTrue($this->lastRequest()->hasHeader('Idempotency-Key'));
        $this->assertSame(RcsReviewStatus::AWAITING_REVIEW, $result['agent']['reviewStatus']);
        $this->assertSame('2026-09-01T10:00:00Z', $result['agent']['submittedForReviewAt']);
        $this->assertSame(RcsCustomerStage::IN_REVIEW, $result['stage']);
    }

    public function testAgentsSubmitHonoursCallerIdempotencyKey(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['agent' => $this->agent(['reviewStatus' => 'awaiting_review']), 'stage' => 'in_review'])),
        ]);

        $client->rcs()->agents->submit('rca_1', 'acme-rcs-submit-1');

        $this->assertSame('acme-rcs-submit-1', $this->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function testAgentsSubmitRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Agent ID is required');
        $client->rcs()->agents->submit('');
    }

    public function testAgentsSubmitIncompleteDraft(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unprocessable Entity',
                new Request('POST', '/rcs/agents/rca_1/submit'),
                new Response(422, [], json_encode([
                    'error' => 'rcs_invalid_content',
                    'message' => 'Some details are missing or invalid.',
                    'errors' => [
                        ['path' => 'brand.ein', 'message' => 'Enter a 9-digit EIN'],
                        ['path' => 'agent.logoUrl', 'message' => 'Must be a public https:// URL'],
                    ],
                ]))
            ),
        ]);

        try {
            $client->rcs()->agents->submit('rca_1');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(RcsErrorCode::INVALID_CONTENT, $e->getApiErrorCode());
            $this->assertSame('brand.ein', $e->getDetails()[0]['path']);
            $this->assertSame('agent.logoUrl', $e->getDetails()[1]['path']);
        }
    }

    public function testAgentsSubmitBrandNotVerified(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Conflict',
                new Request('POST', '/rcs/agents/rca_1/submit'),
                new Response(409, [], json_encode([
                    'error' => 'rcs_brand_not_verified',
                    'message' => 'The brand failed verification on the carrier network.',
                ]))
            ),
        ], ['maxRetries' => 0]);

        try {
            $client->rcs()->agents->submit('rca_1');
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame(RcsErrorCode::BRAND_NOT_VERIFIED, $e->getApiErrorCode());
        }
    }

    // ==================== agents->requestLaunch() ====================

    public function testAgentsRequestLaunch(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'agent' => $this->agent([
                    'status' => 'testing',
                    'reviewStatus' => 'launch_requested',
                    'customerStage' => 'launch_review',
                    'testing' => ['testUrl' => 'https://acme.example/rcs-test', 'messageId' => null, 'additionalInformation' => 'Tested on two handsets'],
                ]),
                'stage' => 'launch_review',
            ])),
        ]);

        $result = $client->rcs()->agents->requestLaunch('rca_1', [
            'testUrl' => 'https://acme.example/rcs-test',
            'testingAdditionalInformation' => 'Tested on two handsets',
            'messageId' => 'stripped',
        ], 'acme-rcs-launch-1');

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/rcs/agents/rca_1/request-launch', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('acme-rcs-launch-1', $this->lastRequest()->getHeaderLine('Idempotency-Key'));
        $this->assertSame([
            'testUrl' => 'https://acme.example/rcs-test',
            'testingAdditionalInformation' => 'Tested on two handsets',
        ], $this->sentBody());
        $this->assertSame(RcsReviewStatus::LAUNCH_REQUESTED, $result['agent']['reviewStatus']);
        $this->assertSame('https://acme.example/rcs-test', $result['agent']['testing']['testUrl']);
        $this->assertSame(RcsCustomerStage::LAUNCH_REVIEW, $result['stage']);
    }

    public function testAgentsRequestLaunchWithoutParams(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['agent' => $this->agent(['reviewStatus' => 'launch_requested']), 'stage' => 'launch_review'])),
        ]);

        $client->rcs()->agents->requestLaunch('rca_1');

        $this->assertSame([], $this->sentBody());
        $this->assertTrue($this->lastRequest()->hasHeader('Idempotency-Key'));
    }

    public function testAgentsRequestLaunchNotReady(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Conflict',
                new Request('POST', '/rcs/agents/rca_1/request-launch'),
                new Response(409, [], json_encode([
                    'error' => 'rcs_launch_not_ready',
                    'message' => "This agent isn't ready to launch yet. Finish testing on an invited device first.",
                ]))
            ),
        ], ['maxRetries' => 0]);

        try {
            $client->rcs()->agents->requestLaunch('rca_1');
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame(RcsErrorCode::LAUNCH_NOT_READY, $e->getApiErrorCode());
            $this->assertStringContainsString("isn't ready to launch", $e->getMessage());
        }
    }

    // ==================== dark posture + scopes ====================

    /**
     * @return array<string, array{string, string, callable(Sendly): array}>
     */
    public static function registrationOperations(): array
    {
        return [
            'registration.get' => ['GET', '/rcs/registration', fn(Sendly $c) => $c->rcs()->registration->get()],
            'dossier.get' => ['GET', '/rcs/dossier', fn(Sendly $c) => $c->rcs()->dossier->get()],
            'brands.create' => ['POST', '/rcs/brands', fn(Sendly $c) => $c->rcs()->brands->create(['legalName' => 'Acme'])],
            'brands.update' => ['PATCH', '/rcs/brands/rcb_1', fn(Sendly $c) => $c->rcs()->brands->update('rcb_1', ['legalName' => 'Acme'])],
            'agents.create' => ['POST', '/rcs/agents', fn(Sendly $c) => $c->rcs()->agents->create(['brandId' => 'rcb_1'])],
            'agents.get' => ['GET', '/rcs/agents/rca_1', fn(Sendly $c) => $c->rcs()->agents->get('rca_1')],
            'agents.update' => ['PATCH', '/rcs/agents/rca_1', fn(Sendly $c) => $c->rcs()->agents->update('rca_1', ['displayName' => 'Acme'])],
            'agents.setTestDevices' => ['PUT', '/rcs/agents/rca_1/test-devices', fn(Sendly $c) => $c->rcs()->agents->setTestDevices('rca_1', ['+15551234567'])],
            'agents.submit' => ['POST', '/rcs/agents/rca_1/submit', fn(Sendly $c) => $c->rcs()->agents->submit('rca_1')],
            'agents.requestLaunch' => ['POST', '/rcs/agents/rca_1/request-launch', fn(Sendly $c) => $c->rcs()->agents->requestLaunch('rca_1')],
        ];
    }

    #[DataProvider('registrationOperations')]
    public function testRegistrationAbsentUntilChannelEnabled(string $method, string $path, callable $call): void
    {
        $client = $this->createMockClient([$this->darkResponse($method, $path)]);

        try {
            $call($client);
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame("RCS registration isn't enabled for this account yet.", $e->getMessage());
            $this->assertSame(RcsErrorCode::NOT_ENABLED, $e->getApiErrorCode());
        }

        $this->assertCount(1, $this->history);
        $this->assertSame($method, $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1' . $path, $this->lastRequest()->getUri()->getPath());
    }

    public function testMissingScopeIsForbidden(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Forbidden',
                new Request('POST', '/rcs/brands'),
                new Response(403, [], json_encode([
                    'error' => 'insufficient_permissions',
                    'message' => 'This API key does not have the rcs:write scope.',
                ]))
            ),
        ], ['maxRetries' => 0]);

        try {
            $client->rcs()->brands->create(['legalName' => 'Acme']);
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame(RcsErrorCode::INSUFFICIENT_PERMISSIONS, $e->getApiErrorCode());
        }
    }
}
