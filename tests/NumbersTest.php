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
use Sendly\Resources\Numbers;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\AuthenticationException;
use Sendly\Exceptions\InsufficientCreditsException;
use ReflectionClass;

/**
 * Tests for Numbers resource: listCountries(), listAvailable(), list(), buy()
 */
class NumbersTest extends TestCase
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

    // ==================== resource registration ====================

    public function testNumbersResourceIsRegistered(): void
    {
        $client = new Sendly('test_api_key');
        $this->assertInstanceOf(Numbers::class, $client->numbers);
        $this->assertInstanceOf(Numbers::class, $client->numbers());
        $this->assertSame($client->numbers, $client->numbers());
    }

    // ==================== listCountries() ====================

    public function testListCountries(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'countries' => [
                    ['code' => 'US', 'name' => 'United States', 'numberTypes' => ['toll_free', 'local']],
                    ['code' => 'GB', 'name' => 'United Kingdom', 'numberTypes' => ['mobile']],
                ],
            ])),
        ]);

        $result = $client->numbers()->listCountries();

        $this->assertCount(2, $result['countries']);
        $this->assertSame('GB', $result['countries'][1]['code']);
        $this->assertSame(['mobile'], $result['countries'][1]['numberTypes']);
    }

    // ==================== listAvailable() ====================

    public function testListAvailable(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'numbers' => [
                    [
                        'phoneNumber' => '+447400000001',
                        'country' => 'GB',
                        'numberType' => 'mobile',
                        'monthlyCost' => '3.00',
                        'currency' => 'USD',
                    ],
                ],
            ])),
        ]);

        $result = $client->numbers()->listAvailable(['country' => 'GB', 'type' => 'mobile']);

        $this->assertCount(1, $result['numbers']);
        $this->assertSame('+447400000001', $result['numbers'][0]['phoneNumber']);
        $this->assertSame('3.00', $result['numbers'][0]['monthlyCost']);
    }

    public function testListAvailableWithContains(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['numbers' => []])),
        ]);

        $result = $client->numbers()->listAvailable([
            'country' => 'GB',
            'type' => 'mobile',
            'contains' => '777',
        ]);

        $this->assertSame([], $result['numbers']);
    }

    public function testListAvailableRequiresCountry(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('country is required');
        $client->numbers()->listAvailable(['type' => 'mobile']);
    }

    public function testListAvailableRequiresType(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('type is required');
        $client->numbers()->listAvailable(['country' => 'GB']);
    }

    // ==================== list() ====================

    public function testList(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'numbers' => [
                    [
                        'id' => 'num_1',
                        'phoneNumber' => '+18005551234',
                        'status' => 'active',
                        'source' => 'purchased',
                        'countryCode' => 'US',
                        'phoneNumberType' => 'toll_free',
                        'monthlyCostCents' => 200,
                    ],
                ],
            ])),
        ]);

        $result = $client->numbers()->list();

        $this->assertCount(1, $result['numbers']);
        $this->assertSame('num_1', $result['numbers'][0]['id']);
        $this->assertSame(200, $result['numbers'][0]['monthlyCostCents']);
    }

    // ==================== buy() ====================

    public function testBuyProvisioning(): void
    {
        $client = $this->createMockClient([
            new Response(202, [], json_encode([
                'status' => 'provisioning',
                'number' => [
                    'id' => 'num_2',
                    'phoneNumber' => '+447400000001',
                    'status' => 'provisioning',
                ],
            ])),
        ]);

        $result = $client->numbers()->buy([
            'phoneNumber' => '+447400000001',
            'countryCode' => 'GB',
            'phoneNumberType' => 'mobile',
            'monthlyCost' => '3.00',
        ]);

        $this->assertSame('provisioning', $result['status']);
        $this->assertSame('num_2', $result['number']['id']);
        $this->assertArrayNotHasKey('action', $result);
    }

    public function testBuyDocumentsRequiredReturnsActionVerbatim(): void
    {
        $client = $this->createMockClient([
            new Response(202, [], json_encode([
                'status' => 'documents_required',
                'requirements' => ['proof_of_address'],
                'action' => [
                    'url' => 'https://sendly.live/action/abc123',
                    'code' => 'ABCD2345',
                    'actionCode' => '0123456789abcdef0123456789abcdef',
                    'expiresAt' => 1780000000000,
                ],
            ])),
        ]);

        $result = $client->numbers()->buy([
            'phoneNumber' => '+447400000001',
            'countryCode' => 'GB',
            'phoneNumberType' => 'mobile',
            'monthlyCost' => '3.00',
        ]);

        $this->assertSame('documents_required', $result['status']);
        $this->assertSame('https://sendly.live/action/abc123', $result['action']['url']);
        // The human-facing user code (display only) is exposed verbatim.
        $this->assertSame('ABCD2345', $result['action']['code']);
        // The 32-hex actionCode (used for polling + re-buy) is exposed verbatim
        // and is a DIFFERENT value from the display code.
        $this->assertSame('0123456789abcdef0123456789abcdef', $result['action']['actionCode']);
        $this->assertNotSame($result['action']['code'], $result['action']['actionCode']);
        // expiresAt is epoch milliseconds (an int), passed through untouched.
        $this->assertSame(1780000000000, $result['action']['expiresAt']);
        // requirements is a JSON array.
        $this->assertSame(['proof_of_address'], $result['requirements']);
    }

    public function testBuyPaymentRequiredReturnsAction(): void
    {
        $client = $this->createMockClient([
            new Response(202, [], json_encode([
                'status' => 'payment_required',
                'action' => [
                    'url' => 'https://sendly.live/action/pay456',
                    'code' => 'PAY45678',
                    'actionCode' => 'fedcba9876543210fedcba9876543210',
                    'expiresAt' => 1780000000000,
                ],
            ])),
        ]);

        $result = $client->numbers()->buy([
            'phoneNumber' => '+447400000001',
            'countryCode' => 'GB',
            'phoneNumberType' => 'mobile',
            'monthlyCost' => '3.00',
        ]);

        $this->assertSame('payment_required', $result['status']);
        $this->assertSame('PAY45678', $result['action']['code']);
        $this->assertSame('fedcba9876543210fedcba9876543210', $result['action']['actionCode']);
    }

    public function testBuyResubmitWithActionCode(): void
    {
        $captured = null;
        $mock = new MockHandler([
            new Response(202, [], json_encode([
                'status' => 'provisioning',
                'number' => [
                    'id' => 'num_3',
                    'phoneNumber' => '+447400000001',
                    'status' => 'provisioning',
                ],
            ])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(\GuzzleHttp\Middleware::mapRequest(function ($request) use (&$captured) {
            $captured = (string) $request->getBody();
            return $request;
        }));
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new Sendly('test_api_key');
        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($client, $httpClient);

        $result = $client->numbers()->buy([
            'phoneNumber' => '+447400000001',
            'countryCode' => 'GB',
            'phoneNumberType' => 'mobile',
            'monthlyCost' => '3.00',
            // Re-buy passes the 32-hex actionCode, not the display code.
            'actionCode' => '0123456789abcdef0123456789abcdef',
        ]);

        $this->assertSame('provisioning', $result['status']);

        // The 32-hex actionCode is forwarded verbatim in the request body.
        $sentBody = json_decode($captured, true);
        $this->assertSame('0123456789abcdef0123456789abcdef', $sentBody['actionCode']);
    }

    public function testBuyRequiresPhoneNumber(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('phoneNumber is required');
        $client->numbers()->buy([
            'countryCode' => 'GB',
            'phoneNumberType' => 'mobile',
            'monthlyCost' => '3.00',
        ]);
    }

    public function testBuyRequiresCountryCode(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('countryCode is required');
        $client->numbers()->buy([
            'phoneNumber' => '+447400000001',
            'phoneNumberType' => 'mobile',
            'monthlyCost' => '3.00',
        ]);
    }

    public function testBuyRequiresPhoneNumberType(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('phoneNumberType is required');
        $client->numbers()->buy([
            'phoneNumber' => '+447400000001',
            'countryCode' => 'GB',
            'monthlyCost' => '3.00',
        ]);
    }

    public function testBuyRequiresMonthlyCost(): void
    {
        $client = new Sendly('test_api_key');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('monthlyCost is required');
        $client->numbers()->buy([
            'phoneNumber' => '+447400000001',
            'countryCode' => 'GB',
            'phoneNumberType' => 'mobile',
        ]);
    }

    // ==================== error propagation ====================

    public function testBuyPaymentRequiredHttpErrorMapsToInsufficientCredits(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Payment Required',
                new Request('POST', '/numbers/buy'),
                new Response(402, [], json_encode(['error' => 'Insufficient credits']))
            ),
        ]);

        $this->expectException(InsufficientCreditsException::class);
        $client->numbers()->buy([
            'phoneNumber' => '+447400000001',
            'countryCode' => 'GB',
            'phoneNumberType' => 'mobile',
            'monthlyCost' => '3.00',
        ]);
    }

    public function testListAuthenticationError(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'Unauthorized',
                new Request('GET', '/numbers'),
                new Response(401, [], json_encode(['error' => 'Invalid API key']))
            ),
        ]);

        $this->expectException(AuthenticationException::class);
        $client->numbers()->list();
    }
}
