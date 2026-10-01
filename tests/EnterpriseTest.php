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
}
