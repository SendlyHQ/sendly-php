<?php

declare(strict_types=1);

namespace Sendly\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use Sendly\Exceptions\SendlyException;
use Sendly\Resources\ShortCodeReviewStatus;
use Sendly\Resources\ShortCodes;
use Sendly\Resources\ShortCodeStatus;
use Sendly\Sendly;

/**
 * Tests for the short codes resource: list(), application->get(),
 * application->update(), application->check() and application->submit()
 */
class ShortCodesTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface, response: ?Response}> */
    private array $history = [];

    /**
     * @param array<int, Response|RequestException> $responses
     */
    private function createMockClient(array $responses): Sendly
    {
        $this->history = [];
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->history));

        $client = new Sendly('test_api_key', ['maxRetries' => 0]);
        (new ReflectionClass($client))->getProperty('httpClient')->setValue(
            $client,
            new Client(['handler' => $handlerStack])
        );

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

    /**
     * @return array<string, mixed>
     */
    private function lease(): array
    {
        return [
            'monthlyCents' => 100000,
            'minimumTermMonths' => 3,
            'state' => 'not_started',
            'billingEnabled' => true,
            'startedAt' => null,
            'nextChargeAt' => null,
            'paidThrough' => null,
            'termEndsAt' => null,
            'endsAt' => null,
            'cancelRequestedAt' => null,
            'pastDue' => null,
            'dueMonths' => 0,
            'dueCents' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function view(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'application' => [
                'id' => 'sc_1',
                'shortCode' => null,
                'countryCode' => 'US',
                'status' => 'requested',
                'reviewStatus' => 'draft',
                'reviewNote' => null,
                'orderType' => 'new',
                'codeType' => 'random',
                'contentProviderSameAsBrand' => true,
                'sampleMessages' => ['Acme: your order shipped. Reply STOP to opt out.'],
                'useCase' => 'Order and delivery alerts',
                'messageFrequency' => 'Up to 4 messages per order',
                'submittedAt' => null,
                'reviewedAt' => null,
                'createdAt' => '2026-08-30T10:00:00.000Z',
                'updatedAt' => '2026-09-03T10:00:00.000Z',
            ],
            'prefill' => ['source' => 'none'],
            'editable' => true,
            'lockMessage' => null,
            'canStartNewApplication' => false,
            'generatedDefaults' => null,
            'requiredDocuments' => ['loa'],
            'missingDocuments' => ['loa'],
            'documents' => [[
                'kind' => 'loa',
                'templateReady' => true,
                'signedAt' => null,
                'signedByName' => null,
                'awaitingSignature' => false,
            ]],
            'quote' => [
                'codeType' => 'random',
                'monthlyUsd' => 1000,
                'setupUsd' => 999,
                'currency' => 'USD',
                'autoBilled' => false,
                'setupCents' => 99900,
                'monthlyCents' => 100000,
                'minimumTermMonths' => 3,
                'setupChargedAt' => 'submit',
                'leaseStartsAt' => 'go_live',
            ],
            'billing' => [
                'setupFee' => [
                    'amountCents' => 99900,
                    'status' => 'unpaid',
                    'chargedAt' => 'submit',
                    'paidAt' => null,
                    'refundedCents' => 0,
                    'refundedAt' => null,
                ],
                'lease' => $this->lease() + ['startsAt' => 'go_live'],
                'terms' => ['version' => '2026-09', 'acceptedVersion' => null, 'acceptedAt' => null],
                'refundPolicy' => 'full_refund_before_filing',
                'exempt' => null,
                'charges' => [],
            ],
            'verification' => ['verified' => true, 'state' => 'verified', 'href' => '/verify'],
            'carriers' => [
                'approved' => 0,
                'total' => 4,
                'overall' => 'not_submitted',
                'byCarrier' => [
                    ['carrier' => 'ATT', 'label' => 'AT&T', 'status' => 'not_submitted', 'updatedAt' => null],
                ],
            ],
        ], $overrides);
    }

    public function testTheClientExposesShortCodes(): void
    {
        $client = new Sendly('test_api_key');

        $this->assertInstanceOf(ShortCodes::class, $client->shortCodes());
        $this->assertSame($client->shortCodes, $client->shortCodes());
    }

    public function testListReadsEveryCodeWithItsBilling(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['shortCodes' => [[
                'id' => 'sc_1',
                'shortCode' => '55123',
                'countryCode' => 'US',
                'status' => 'active',
                'reviewStatus' => 'filed',
                'useCase' => 'Order and delivery alerts',
                'createdAt' => '2026-08-30T10:00:00.000Z',
                'billing' => [
                    'setupFee' => ['status' => 'paid', 'amountCents' => 99900],
                    'lease' => $this->lease(),
                ],
            ]]])),
        ]);

        $result = $client->shortCodes()->list();

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/short_codes', $this->lastRequest()->getUri()->getPath());
        $code = $result['shortCodes'][0];
        $this->assertSame('55123', $code['shortCode']);
        $this->assertSame(ShortCodeStatus::ACTIVE, $code['status']);
        $this->assertSame(ShortCodeReviewStatus::FILED, $code['reviewStatus']);
        $this->assertSame('paid', $code['billing']['setupFee']['status']);
        $this->assertSame(100000, $code['billing']['lease']['monthlyCents']);
    }

    public function testApplicationGetReadsTheView(): void
    {
        $client = $this->createMockClient([new Response(200, [], json_encode($this->view()))]);

        $view = $client->shortCodes()->application->get();

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/short_codes/application', $this->lastRequest()->getUri()->getPath());
        $this->assertTrue($view['editable']);
        $this->assertSame('sc_1', $view['application']['id']);
        $this->assertSame(99900, $view['quote']['setupCents']);
        $this->assertSame(['loa'], $view['missingDocuments']);
        $this->assertSame('verified', $view['verification']['state']);
    }

    public function testApplicationGetWhenNotEnabledIsNotFound(): void
    {
        $client = $this->createMockClient([
            new RequestException(
                'HTTP 404',
                new Request('GET', '/short_codes/application'),
                new Response(404, [], json_encode([
                    'error' => 'short_codes_not_enabled',
                    'message' => 'Short codes are not enabled for this account.',
                ]))
            ),
        ]);

        try {
            $client->shortCodes()->application->get();
            $this->fail('Expected SendlyException');
        } catch (SendlyException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('short_codes_not_enabled', $e->getApiErrorCode());
        }
    }

    public function testApplicationUpdatePutsTheAnswersWithTheCallersKey(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->view(['ignoredFields' => ['shortCode']]))),
        ]);

        $view = $client->shortCodes()->application->update([
            'useCase' => 'Delivery alerts for Acme orders',
            'messageFrequency' => '4 messages per month',
            'shortCode' => '55555',
        ], 'save-acme-1');

        $this->assertSame('PUT', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/short_codes/application', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('save-acme-1', $this->lastRequest()->getHeaderLine('Idempotency-Key'));
        $this->assertSame([
            'useCase' => 'Delivery alerts for Acme orders',
            'messageFrequency' => '4 messages per month',
            'shortCode' => '55555',
        ], $this->sentBody());
        $this->assertSame(['shortCode'], $view['ignoredFields']);
    }

    public function testApplicationUpdateWithAnEmptyBodySendsAnObject(): void
    {
        $client = $this->createMockClient([new Response(200, [], json_encode($this->view()))]);

        $client->shortCodes()->application->update([]);

        $this->assertSame('{}', (string) $this->lastRequest()->getBody());
        $this->assertFalse($this->lastRequest()->hasHeader('Idempotency-Key'));
    }

    public function testApplicationCheckPostsUnsavedAnswers(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'ok' => false,
                'issues' => [['path' => 'helpResponse', 'message' => 'Add the reply to HELP.']],
                'requiredDocuments' => ['loa'],
                'missingDocuments' => ['loa'],
                'documents' => [],
            ])),
        ]);

        $result = $client->shortCodes()->application->check(['campaignKeyword' => 'JOIN']);

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/short_codes/application/preflight', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['campaignKeyword' => 'JOIN'], $this->sentBody());
        $this->assertFalse($result['ok']);
        $this->assertSame('helpResponse', $result['issues'][0]['path']);
    }

    public function testApplicationCheckWithoutAnswersSendsAnObject(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'ok' => true,
                'issues' => [],
                'requiredDocuments' => [],
                'missingDocuments' => [],
                'documents' => [],
            ])),
        ]);

        $result = $client->shortCodes()->application->check();

        $this->assertSame('{}', (string) $this->lastRequest()->getBody());
        $this->assertTrue($result['ok']);
    }

    public function testApplicationSubmitPostsAcceptTermsWithTheCallersKey(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->view([
                'editable' => false,
                'submitted' => true,
                'alreadySubmitted' => false,
                'notified' => true,
                'payment' => ['status' => 'paid', 'charged' => true],
            ]))),
        ]);

        $result = $client->shortCodes()->application->submit(['acceptTerms' => true], 'submit-acme-1');

        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/api/v1/short_codes/application/submit', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('submit-acme-1', $this->lastRequest()->getHeaderLine('Idempotency-Key'));
        $this->assertSame(['acceptTerms' => true], $this->sentBody());
        $this->assertTrue($result['submitted']);
        $this->assertSame(['status' => 'paid', 'charged' => true], $result['payment']);
    }

    public function testApplicationSubmitWithoutAKeySendsAnAutoKey(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->view(['submitted' => true, 'alreadySubmitted' => true]))),
        ]);

        $client->shortCodes()->application->submit(['acceptTerms' => true]);

        $this->assertNotSame('', $this->lastRequest()->getHeaderLine('Idempotency-Key'));
    }
}
