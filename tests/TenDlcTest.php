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
use Sendly\Resources\TenDlc;
use Sendly\Exceptions\SendlyException;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\AuthenticationException;
use Sendly\Exceptions\NotFoundException;
use ReflectionClass;

/**
 * Tests for TenDlc resource: listBrands(), createBrand(), getBrand(),
 * qualify(), listCampaigns(), createCampaign(), getCampaign(),
 * assignNumber(), listAssignments()
 */
class TenDlcTest extends TestCase
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

    private function createCapturingClient(array $responses, ?string &$capturedBody, ?string &$capturedPath = null): Sendly
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(\GuzzleHttp\Middleware::mapRequest(function ($request) use (&$capturedBody, &$capturedPath) {
            $capturedBody = (string) $request->getBody();
            $capturedPath = $request->getUri()->getPath();
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

    private function brand(array $overrides = []): array
    {
        return array_merge([
            'id' => 'brd_1',
            'legalName' => 'Acme Holdings LLC',
            'dba' => null,
            'entityType' => 'PRIVATE_PROFIT',
            'ein' => '12-3456789',
            'vertical' => 'TECHNOLOGY',
            'website' => 'https://acme.example',
            'status' => 'pending',
            'identityStatus' => null,
            'failureReasons' => null,
            'createdAt' => '2026-06-30T12:00:00Z',
            'updatedAt' => '2026-06-30T12:00:00Z',
        ], $overrides);
    }

    private function campaign(array $overrides = []): array
    {
        return array_merge([
            'id' => 'cmp_1',
            'brandId' => 'brd_1',
            'useCase' => 'MIXED',
            'subUseCases' => [],
            'description' => 'Order updates and support replies',
            'status' => 'pending',
            'sampleMessages' => ['Your order #123 has shipped!'],
            'throughput' => null,
            'failureReasons' => null,
            'createdAt' => '2026-06-30T12:00:00Z',
            'updatedAt' => '2026-06-30T12:00:00Z',
        ], $overrides);
    }

    // ==================== resource registration ====================

    public function testTenDlcResourceIsRegistered(): void
    {
        $client = new Sendly('test_api_key');
        $this->assertInstanceOf(TenDlc::class, $client->tenDlc);
        $this->assertInstanceOf(TenDlc::class, $client->tenDlc());
        $this->assertSame($client->tenDlc, $client->tenDlc());
    }

    // ==================== listBrands() ====================

    public function testListBrands(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    $this->brand(),
                    $this->brand(['id' => 'brd_2', 'legalName' => 'Beta LLC', 'status' => 'verified']),
                ],
            ])),
        ]);

        $result = $client->tenDlc()->listBrands();

        $this->assertCount(2, $result['data']);
        $this->assertSame('brd_2', $result['data'][1]['id']);
        $this->assertSame('verified', $result['data'][1]['status']);
    }

    // ==================== createBrand() ====================

    public function testCreateBrand(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode(['data' => $this->brand()])),
        ]);

        $result = $client->tenDlc()->createBrand([
            'legalName' => 'Acme Holdings LLC',
            'ein' => '12-3456789',
            'website' => 'https://acme.example',
            'email' => 'ops@acme.example',
        ]);

        $this->assertSame('brd_1', $result['data']['id']);
        $this->assertSame('pending', $result['data']['status']);
    }

    public function testCreateBrandSendsOnlyProvidedFields(): void
    {
        $capturedBody = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode(['data' => $this->brand()])),
        ], $capturedBody);

        $client->tenDlc()->createBrand([
            'legalName' => 'Acme Holdings LLC',
            'entityType' => 'SOLE_PROPRIETOR',
            'mobilePhone' => '+15551230000',
        ]);

        $sentBody = json_decode($capturedBody, true);
        $this->assertSame('Acme Holdings LLC', $sentBody['legalName']);
        $this->assertSame('SOLE_PROPRIETOR', $sentBody['entityType']);
        $this->assertSame('+15551230000', $sentBody['mobilePhone']);
        $this->assertArrayNotHasKey('ein', $sentBody);
        $this->assertArrayNotHasKey('country', $sentBody);
    }

    public function testCreateBrandRequiresLegalName(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('legalName is required');
        $client->tenDlc()->createBrand(['ein' => '12-3456789']);
    }

    // ==================== getBrand() ====================

    public function testGetBrand(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $client = $this->createCapturingClient([
            new Response(200, [], json_encode([
                'data' => $this->brand(['status' => 'verified', 'identityStatus' => 'VERIFIED']),
            ])),
        ], $capturedBody, $capturedPath);

        $result = $client->tenDlc()->getBrand('brd_1');

        $this->assertSame('/tendlc/brands/brd_1', $capturedPath);
        $this->assertSame('verified', $result['data']['status']);
        $this->assertSame('VERIFIED', $result['data']['identityStatus']);
    }

    public function testGetBrandFailedIncludesFailureReasons(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => $this->brand(['status' => 'failed', 'failureReasons' => ['Business address could not be confirmed']]),
            ])),
        ]);

        $result = $client->tenDlc()->getBrand('brd_1');

        $this->assertSame('failed', $result['data']['status']);
        $this->assertSame(['Business address could not be confirmed'], $result['data']['failureReasons']);
    }

    public function testGetBrandRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Brand ID is required');
        $client->tenDlc()->getBrand('');
    }

    public function testGetBrandNotFound(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Not Found',
                new Request('GET', '/tendlc/brands/brd_missing'),
                new Response(404, [], json_encode(['error' => 'not_found']))
            ),
        ]);

        $this->expectException(NotFoundException::class);
        $client->tenDlc()->getBrand('brd_missing');
    }

    // ==================== qualify() ====================

    public function testQualify(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $client = $this->createCapturingClient([
            new Response(200, [], json_encode([
                'data' => [
                    'useCase' => 'MIXED',
                    'qualified' => true,
                    'reason' => null,
                    'throughput' => ['tier' => 'Standard', 'carriersReady' => 3],
                ],
            ])),
        ], $capturedBody, $capturedPath);

        $result = $client->tenDlc()->qualify('brd_1', 'MIXED');

        $this->assertSame('/tendlc/brands/brd_1/qualify/MIXED', $capturedPath);
        $this->assertTrue($result['data']['qualified']);
        $this->assertSame('Standard', $result['data']['throughput']['tier']);
        $this->assertSame(3, $result['data']['throughput']['carriersReady']);
    }

    public function testQualifyNotQualifiedIncludesReason(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    'useCase' => 'MARKETING',
                    'qualified' => false,
                    'reason' => 'This use case is not available for your brand yet',
                    'throughput' => null,
                ],
            ])),
        ]);

        $result = $client->tenDlc()->qualify('brd_1', 'MARKETING');

        $this->assertFalse($result['data']['qualified']);
        $this->assertSame('This use case is not available for your brand yet', $result['data']['reason']);
        $this->assertNull($result['data']['throughput']);
    }

    public function testQualifyRequiresBrandId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Brand ID is required');
        $client->tenDlc()->qualify('', 'MIXED');
    }

    public function testQualifyRequiresUseCase(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Use case is required');
        $client->tenDlc()->qualify('brd_1', '');
    }

    // ==================== listCampaigns() ====================

    public function testListCampaigns(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    $this->campaign(),
                    $this->campaign(['id' => 'cmp_2', 'status' => 'active', 'throughput' => ['tier' => 'Standard', 'carriersReady' => 4]]),
                ],
            ])),
        ]);

        $result = $client->tenDlc()->listCampaigns();

        $this->assertCount(2, $result['data']);
        $this->assertSame('cmp_2', $result['data'][1]['id']);
        $this->assertSame('active', $result['data'][1]['status']);
        $this->assertSame(4, $result['data'][1]['throughput']['carriersReady']);
    }

    // ==================== createCampaign() ====================

    public function testCreateCampaign(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode(['data' => $this->campaign()])),
        ]);

        $result = $client->tenDlc()->createCampaign([
            'brandId' => 'brd_1',
            'useCase' => 'MIXED',
            'description' => 'Order updates and support replies',
            'messageFlow' => 'Customers opt in at checkout on acme.example',
            'sampleMessages' => ['Your order #123 has shipped!'],
        ]);

        $this->assertSame('cmp_1', $result['data']['id']);
        $this->assertSame('pending', $result['data']['status']);
    }

    public function testCreateCampaignForwardsOptionalFields(): void
    {
        $capturedBody = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode(['data' => $this->campaign()])),
        ], $capturedBody);

        $client->tenDlc()->createCampaign([
            'brandId' => 'brd_1',
            'useCase' => 'MIXED',
            'description' => 'Order updates and support replies',
            'messageFlow' => 'Customers opt in at checkout on acme.example',
            'sampleMessages' => ['Your order #123 has shipped!'],
            'optOutKeywords' => 'STOP',
            'embeddedLink' => false,
        ]);

        $sentBody = json_decode($capturedBody, true);
        $this->assertSame('STOP', $sentBody['optOutKeywords']);
        $this->assertFalse($sentBody['embeddedLink']);
        $this->assertArrayNotHasKey('embeddedPhone', $sentBody);
        $this->assertArrayNotHasKey('optInKeywords', $sentBody);
    }

    public function testCreateCampaignRequiresBrandId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('brandId is required');
        $client->tenDlc()->createCampaign([
            'useCase' => 'MIXED',
            'description' => 'Order updates',
            'messageFlow' => 'Opt in at checkout',
            'sampleMessages' => ['Hi!'],
        ]);
    }

    public function testCreateCampaignRequiresUseCase(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('useCase is required');
        $client->tenDlc()->createCampaign([
            'brandId' => 'brd_1',
            'description' => 'Order updates',
            'messageFlow' => 'Opt in at checkout',
            'sampleMessages' => ['Hi!'],
        ]);
    }

    public function testCreateCampaignRequiresDescription(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('description is required');
        $client->tenDlc()->createCampaign([
            'brandId' => 'brd_1',
            'useCase' => 'MIXED',
            'messageFlow' => 'Opt in at checkout',
            'sampleMessages' => ['Hi!'],
        ]);
    }

    public function testCreateCampaignRequiresMessageFlow(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('messageFlow is required');
        $client->tenDlc()->createCampaign([
            'brandId' => 'brd_1',
            'useCase' => 'MIXED',
            'description' => 'Order updates',
            'sampleMessages' => ['Hi!'],
        ]);
    }

    public function testCreateCampaignRequiresSampleMessages(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sampleMessages is required');
        $client->tenDlc()->createCampaign([
            'brandId' => 'brd_1',
            'useCase' => 'MIXED',
            'description' => 'Order updates',
            'messageFlow' => 'Opt in at checkout',
            'sampleMessages' => [],
        ]);
    }

    // ==================== getCampaign() ====================

    public function testGetCampaign(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $client = $this->createCapturingClient([
            new Response(200, [], json_encode([
                'data' => $this->campaign(['status' => 'active', 'throughput' => ['tier' => 'Standard', 'carriersReady' => 4]]),
            ])),
        ], $capturedBody, $capturedPath);

        $result = $client->tenDlc()->getCampaign('cmp_1');

        $this->assertSame('/tendlc/campaigns/cmp_1', $capturedPath);
        $this->assertSame('active', $result['data']['status']);
        $this->assertSame('Standard', $result['data']['throughput']['tier']);
    }

    public function testGetCampaignRequiresId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Campaign ID is required');
        $client->tenDlc()->getCampaign('');
    }

    public function testGetCampaignNotFound(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Not Found',
                new Request('GET', '/tendlc/campaigns/cmp_missing'),
                new Response(404, [], json_encode(['error' => 'not_found']))
            ),
        ]);

        $this->expectException(NotFoundException::class);
        $client->tenDlc()->getCampaign('cmp_missing');
    }

    // ==================== assignNumber() ====================

    public function testAssignNumber(): void
    {
        $capturedBody = null;
        $capturedPath = null;
        $client = $this->createCapturingClient([
            new Response(201, [], json_encode([
                'data' => [
                    'id' => 'asn_1',
                    'campaignId' => 'cmp_1',
                    'phoneNumber' => '+15551234567',
                    'status' => 'Under review',
                    'assignedAt' => null,
                ],
            ])),
        ], $capturedBody, $capturedPath);

        $result = $client->tenDlc()->assignNumber('cmp_1', '+15551234567');

        $this->assertSame('/tendlc/campaigns/cmp_1/assign', $capturedPath);
        $sentBody = json_decode($capturedBody, true);
        $this->assertSame(['phoneNumber' => '+15551234567'], $sentBody);
        $this->assertSame('asn_1', $result['data']['id']);
        $this->assertSame('Under review', $result['data']['status']);
        $this->assertNull($result['data']['assignedAt']);
    }

    public function testAssignNumberRequiresCampaignId(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Campaign ID is required');
        $client->tenDlc()->assignNumber('', '+15551234567');
    }

    public function testAssignNumberRequiresPhoneNumber(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('phoneNumber is required');
        $client->tenDlc()->assignNumber('cmp_1', '');
    }

    public function testAssignNumberConflictMapsToSendlyException(): void
    {
        // 409 is retried with backoff, so disable retries for this test.
        $client = $this->createMockClient([
            new RequestException(
                'Conflict',
                new Request('POST', '/tendlc/campaigns/cmp_1/assign'),
                new Response(409, [], json_encode([
                    'error' => 'number_already_assigned',
                    'message' => 'This number is already assigned to another campaign',
                ]))
            ),
        ], ['maxRetries' => 0]);

        try {
            $client->tenDlc()->assignNumber('cmp_1', '+15551234567');
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame('This number is already assigned to another campaign', $e->getMessage());
        }
    }

    public function testAssignNumberValidationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Bad Request',
                new Request('POST', '/tendlc/campaigns/cmp_1/assign'),
                new Response(400, [], json_encode([
                    'error' => 'campaign_not_active',
                    'message' => 'The campaign is not active yet',
                ]))
            ),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The campaign is not active yet');
        $client->tenDlc()->assignNumber('cmp_1', '+15551234567');
    }

    // ==================== listAssignments() ====================

    public function testListAssignments(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'data' => [
                    [
                        'id' => 'asn_1',
                        'campaignId' => 'cmp_1',
                        'phoneNumber' => '+15551234567',
                        'status' => 'Active',
                        'assignedAt' => '2026-06-30T12:00:00Z',
                    ],
                ],
            ])),
        ]);

        $result = $client->tenDlc()->listAssignments();

        $this->assertCount(1, $result['data']);
        $this->assertSame('+15551234567', $result['data'][0]['phoneNumber']);
        $this->assertSame('Active', $result['data'][0]['status']);
        $this->assertSame('2026-06-30T12:00:00Z', $result['data'][0]['assignedAt']);
    }

    // ==================== error propagation ====================

    public function testListBrandsAuthenticationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unauthorized',
                new Request('GET', '/tendlc/brands'),
                new Response(401, [], json_encode(['error' => 'Invalid API key']))
            ),
        ]);

        $this->expectException(AuthenticationException::class);
        $client->tenDlc()->listBrands();
    }
}
