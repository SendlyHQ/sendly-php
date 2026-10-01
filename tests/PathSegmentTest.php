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

class PathSegmentTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface, response: ?Response}> */
    private array $history = [];

    /**
     * @param array<int, Response> $responses
     */
    private function createMockClient(array $responses = []): Sendly
    {
        $this->history = [];
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($this->history));
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new Sendly('test_api_key');
        (new ReflectionClass($client))->getProperty('httpClient')->setValue($client, $httpClient);

        return $client;
    }

    private function assertRefusedBeforeSending(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertCount(0, $this->history);
        }
    }

    public function testRevokeKeyRefusesADotSegmentId(): void
    {
        foreach (['.', '..'] as $id) {
            $client = $this->createMockClient([new Response(204)]);

            $this->assertRefusedBeforeSending(fn () => $client->enterprise->workspaces->revokeKey('ws_1', $id));
        }
    }

    public function testDeleteOptInPageRefusesADotSegmentId(): void
    {
        foreach (['.', '..'] as $id) {
            $client = $this->createMockClient([new Response(204)]);

            $this->assertRefusedBeforeSending(fn () => $client->enterprise->workspaces->deleteOptInPage('ws_1', $id));
        }
    }

    public function testCancelInvitationRefusesADotSegmentId(): void
    {
        foreach (['.', '..'] as $id) {
            $client = $this->createMockClient([new Response(204)]);

            $this->assertRefusedBeforeSending(fn () => $client->enterprise->workspaces->cancelInvitation('ws_1', $id));
        }
    }

    public function testRemoveContactRefusesADotSegmentId(): void
    {
        foreach (['.', '..'] as $id) {
            $client = $this->createMockClient([new Response(204)]);

            $this->assertRefusedBeforeSending(fn () => $client->contacts->lists()->removeContact('list_1', $id));
        }
    }

    public function testGetWorkspaceRefusesADotSegmentId(): void
    {
        foreach (['.', '..'] as $id) {
            $client = $this->createMockClient([new Response(200, [], '{}')]);

            $this->assertRefusedBeforeSending(fn () => $client->enterprise->workspaces->get($id));
        }
    }

    public function testARawPathWithADotSegmentIsRefused(): void
    {
        $client = $this->createMockClient([new Response(204)]);

        $this->assertRefusedBeforeSending(fn () => $client->delete('/enterprise/workspaces/ws_1/keys/..'));
    }

    public function testVerifyRefusesAnEmptyId(): void
    {
        $client = $this->createMockClient([new Response(200, [], '{}')]);

        $this->assertRefusedBeforeSending(fn () => $client->verify->get(''));
        $this->assertRefusedBeforeSending(fn () => $client->verify->resend(''));
        $this->assertRefusedBeforeSending(fn () => $client->verify->check('', '123456'));
    }

    public function testAnIdWithDotsInsideItIsSent(): void
    {
        $client = $this->createMockClient([new Response(204)]);

        $client->enterprise->workspaces->revokeKey('ws_1', 'key..1');

        $this->assertCount(1, $this->history);
        $this->assertSame(
            '/api/v1/enterprise/workspaces/ws_1/keys/key..1',
            $this->history[0]['request']->getUri()->getPath()
        );
    }

    public function testAQueryValueOfDotDotIsSent(): void
    {
        $client = $this->createMockClient([new Response(200, [], '{"data":[]}')]);

        $client->get('/contacts', ['search' => '..']);

        $this->assertCount(1, $this->history);
        $this->assertSame('/api/v1/contacts', $this->history[0]['request']->getUri()->getPath());
    }
}
