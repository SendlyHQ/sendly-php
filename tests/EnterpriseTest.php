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

/**
 * Tests for the Enterprise resource
 */
class EnterpriseTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }

    // ==================== workspaces->inheritVerification() ====================

    public function testInheritVerificationCanOrderANewNumber(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode([
                'verificationId' => 'bv_new',
                'status' => 'submitted',
                'type' => 'toll_free',
                'tollFreeNumber' => '+18885550100',
                'inheritedFrom' => 'ws_src',
                'newNumber' => true,
            ])),
        ]);

        $result = $client->enterprise->workspaces->inheritVerification('ws_new', [
            'sourceWorkspaceId' => 'ws_src',
            'purchaseNewNumber' => true,
        ]);

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/enterprise/workspaces/ws_new/verification/inherit', $request->getUri()->getPath());
        $this->assertTrue($this->sentBody()['purchaseNewNumber']);
        $this->assertSame('ws_src', $this->sentBody()['source_workspace_id']);
        $this->assertTrue($result['newNumber']);
        $this->assertSame('+18885550100', $result['tollFreeNumber']);
    }

    public function testInheritVerificationReportsANewNumberEvenWhenNoneCouldBeOrdered(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode([
                'verificationId' => 'bv_new',
                'status' => 'pending',
                'type' => 'toll_free',
                'tollFreeNumber' => null,
                'inheritedFrom' => 'ws_src',
                'newNumber' => true,
            ])),
        ]);

        $result = $client->enterprise->workspaces->inheritVerification('ws_new', [
            'sourceWorkspaceId' => 'ws_src',
            'purchaseNewNumber' => true,
        ]);

        $this->assertTrue($result['newNumber']);
        $this->assertNull($result['tollFreeNumber']);

        $doc = (string) (new \ReflectionMethod(\Sendly\Resources\EnterpriseWorkspaces::class, 'inheritVerification'))->getDocComment();
        $this->assertStringNotContainsString('when a number was ordered', $doc);
        $this->assertStringContainsString('`tollFreeNumber` is null', $doc);
    }

    public function testInheritVerificationSharesTheNumberByDefault(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode([
                'verificationId' => 'bv_new',
                'status' => 'verified',
                'type' => 'toll_free',
                'tollFreeNumber' => '+18885550199',
                'inheritedFrom' => 'ws_src',
            ])),
        ]);

        $client->enterprise->workspaces->inheritVerification('ws_new', ['sourceWorkspaceId' => 'ws_src']);

        $this->assertSame(['source_workspace_id' => 'ws_src'], $this->sentBody());
        $this->assertArrayNotHasKey('purchaseNewNumber', $this->sentBody());
    }

    public function testInheritVerificationSendsAnExplicitFalse(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode(['verificationId' => 'bv_new', 'status' => 'verified'])),
        ]);

        $client->enterprise->workspaces->inheritVerification('ws_new', [
            'sourceWorkspaceId' => 'ws_src',
            'purchaseNewNumber' => false,
        ]);

        $this->assertFalse($this->sentBody()['purchaseNewNumber']);
    }

    public function testInheritVerificationRequiresTheSourceWorkspace(): void
    {
        $client = $this->createMockClient([]);

        $this->expectException(ValidationException::class);

        try {
            $client->enterprise->workspaces->inheritVerification('ws_new', ['purchaseNewNumber' => true]);
        } finally {
            $this->assertCount(0, $this->history);
        }
    }

    // ==================== workspaces->provisionBulk() ====================

    public function testProvisionBulkSendsUpToOneHundredWorkspaces(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode(['results' => []])),
        ]);

        $workspaces = array_map(fn (int $i): array => ['name' => "Workspace {$i}"], range(1, 100));

        $client->enterprise->workspaces->provisionBulk($workspaces);

        $this->assertCount(1, $this->history);
        $this->assertCount(100, $this->sentBody()['workspaces']);
    }

    public function testProvisionBulkRefusesMoreThanOneHundredWorkspaces(): void
    {
        $client = $this->createMockClient([]);

        $workspaces = array_map(fn (int $i): array => ['name' => "Workspace {$i}"], range(1, 101));

        try {
            $client->enterprise->workspaces->provisionBulk($workspaces);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('Maximum 100 workspaces per bulk provision', $e->getMessage());
        }

        $this->assertCount(0, $this->history);
    }

    // ==================== uploadVerificationDocument() ====================

    private function documentFile(string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'sendly-doc-');
        $path = $base . $extension;
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 16));
        register_shutdown_function(static function () use ($base, $path): void {
            @unlink($base);
            @unlink($path);
        });

        return $path;
    }

    public function testUploadVerificationDocumentSendsAGivenContentType(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['url' => 'https://cdn.example.com/doc.png', 'id' => 'upload_1'])),
        ]);
        $file = $this->documentFile('');

        $result = $client->enterprise()->uploadVerificationDocument($file, [
            'contentType' => 'image/png',
            'verificationId' => 'ver_1',
        ]);

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/enterprise/verification-document/upload', $request->getUri()->getPath());
        $body = (string) $request->getBody();
        $this->assertStringContainsString('name="file"', $body);
        $this->assertStringContainsString('Content-Type: image/png', $body);
        $this->assertStringContainsString('name="verificationId"', $body);
        $this->assertStringNotContainsString('contentType', $body);
        $this->assertSame('upload_1', $result['id']);
    }

    public function testUploadVerificationDocumentGuessesTheTypeFromTheExtension(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['url' => 'https://cdn.example.com/doc.pdf', 'id' => 'upload_2'])),
        ]);

        $client->enterprise()->uploadVerificationDocument($this->documentFile('.pdf'));

        $this->assertStringContainsString('Content-Type: application/pdf', (string) $this->history[0]['request']->getBody());
    }

    // ==================== workspaces->list() ====================

    public function testWorkspacesListSendsTheFilters(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['workspaces' => [], 'pagination' => ['page' => 2, 'limit' => 25]])),
        ]);

        $client->enterprise()->workspaces->list([
            'limit' => 25,
            'page' => 2,
            'search' => 'acme',
            'status' => 'active',
            'verification' => 'unverified',
            'sort' => 'name_asc',
            'tags' => ['tag_1', 'tag_2'],
        ]);

        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/v1/enterprise/workspaces', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame([
            'limit' => '25',
            'page' => '2',
            'search' => 'acme',
            'status' => 'active',
            'verification' => 'unverified',
            'sort' => 'name_asc',
            'tags' => 'tag_1,tag_2',
        ], $query);
    }

    public function testWorkspacesListCompactSendsTrue(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['workspaces' => [['id' => 'org_1', 'name' => 'Acme', 'balance' => 10]]])),
        ]);

        $client->enterprise()->workspaces->list(['compact' => true]);

        $this->assertSame('compact=true', $this->history[0]['request']->getUri()->getQuery());
    }

    public function testWorkspacesListWithoutOptionsSendsNoQuery(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['workspaces' => []])),
        ]);

        $client->enterprise()->workspaces->list();

        $this->assertSame('', $this->history[0]['request']->getUri()->getQuery());
    }

    private const FIXTURE_SUMMARY = [
        'totalCredits' => 1500,
        'pendingVerifications' => [
            ['id' => '00000000-0000-4000-8000-000000000011', 'name' => 'Bright Smiles Dental'],
        ],
        'verificationBreakdown' => [
            'verified' => 1,
            'pending' => 1,
            'submitted' => 0,
            'rejected' => 0,
            'unverified' => 0,
        ],
    ];

    public function testWorkspacesListReturnsTheFullListWithItsSummary(): void
    {
        $body = [
            'workspaces' => [
                [
                    'id' => '00000000-0000-4000-8000-000000000011',
                    'name' => 'Bright Smiles Dental',
                    'slug' => 'bright-smiles-dental',
                    'status' => 'active',
                    'suspendedAt' => null,
                    'suspendReason' => null,
                    'verificationStatus' => 'pending',
                    'rejectionReason' => null,
                    'verificationType' => 'toll_free',
                    'tollFreeNumber' => null,
                    'creditBalance' => 0,
                    'keyCount' => 1,
                    'messages30d' => 0,
                    'delivered30d' => 0,
                    'failed30d' => 0,
                    'monthlyMessageQuota' => null,
                    'messagesThisMonth' => 0,
                    'quotaResetAt' => null,
                    'createdAt' => '2026-01-08T00:00:00.000Z',
                    'tags' => [[
                        'id' => '00000000-0000-4000-8000-000000000030',
                        'enterpriseAccountId' => '00000000-0000-4000-8000-000000000031',
                        'name' => 'dental',
                        'color' => '#6366f1',
                        'createdAt' => '2026-01-01T00:00:00.000Z',
                    ]],
                ],
                [
                    'id' => '00000000-0000-4000-8000-000000000010',
                    'name' => 'Acme Clinics',
                    'slug' => 'acme-clinics',
                    'status' => 'active',
                    'suspendedAt' => null,
                    'suspendReason' => null,
                    'verificationStatus' => 'verified',
                    'rejectionReason' => null,
                    'verificationType' => 'toll_free',
                    'tollFreeNumber' => '+15555550100',
                    'creditBalance' => 1500,
                    'keyCount' => 1,
                    'messages30d' => 120,
                    'delivered30d' => 118,
                    'failed30d' => 2,
                    'monthlyMessageQuota' => null,
                    'messagesThisMonth' => 40,
                    'quotaResetAt' => null,
                    'createdAt' => '2026-01-01T00:00:00.000Z',
                    'tags' => [],
                ],
            ],
            'pagination' => ['total' => 2, 'limit' => 50, 'page' => 1, 'totalPages' => 1, 'hasMore' => false],
            'summary' => self::FIXTURE_SUMMARY,
            'maxWorkspaces' => 25,
            'workspacesUsed' => 2,
        ];
        $client = $this->createMockClient([new Response(200, [], json_encode($body))]);

        $result = $client->enterprise()->workspaces->list();

        $this->assertSame(['Bright Smiles Dental', 'Acme Clinics'], array_column($result['workspaces'], 'name'));
        $this->assertSame(2, $result['pagination']['total']);
        $this->assertSame(self::FIXTURE_SUMMARY, $result['summary']);
    }

    public function testWorkspacesListReturnsTheCompactListWithItsSummary(): void
    {
        $body = [
            'workspaces' => [
                ['id' => '00000000-0000-4000-8000-000000000011', 'name' => 'Bright Smiles Dental', 'creditBalance' => 0],
                ['id' => '00000000-0000-4000-8000-000000000010', 'name' => 'Acme Clinics', 'creditBalance' => 1500],
            ],
            'summary' => self::FIXTURE_SUMMARY,
            'maxWorkspaces' => 25,
            'workspacesUsed' => 2,
        ];
        $client = $this->createMockClient([new Response(200, [], json_encode($body))]);

        $result = $client->enterprise()->workspaces->list(['compact' => true]);

        $this->assertSame(1500, $result['workspaces'][1]['creditBalance']);
        $this->assertArrayNotHasKey('pagination', $result);
        $this->assertSame(self::FIXTURE_SUMMARY, $result['summary']);
    }
}
