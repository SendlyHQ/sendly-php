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
use Sendly\Sendly;

class ConversationsTest extends TestCase
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
        (new ReflectionClass($client))->getProperty('httpClient')->setValue($client, $httpClient);

        return $client;
    }

    public function testSuggestRepliesPostsAndReturnsTheSuggestions(): void
    {
        $client = $this->createMockClient([new Response(200, [], json_encode([
            'suggestions' => [
                ['text' => 'Yes, we open at 9am.', 'tone' => 'professional'],
                ['text' => 'See you at 9!', 'tone' => 'friendly'],
            ],
            'basedOnMessageId' => 'msg_in_1',
            'model' => 'gpt-4o-mini',
        ]))]);

        $result = $client->conversations()->suggestReplies('conv/1');

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/conversations/conv%2F1/suggest-replies', $request->getUri()->getPath());
        $this->assertCount(2, $result['suggestions']);
        $this->assertSame('friendly', $result['suggestions'][1]['tone']);
        $this->assertSame('msg_in_1', $result['basedOnMessageId']);
        $this->assertArrayNotHasKey('disabled', $result);
    }

    public function testSuggestRepliesReturnsDisabledAndReason(): void
    {
        $client = $this->createMockClient([new Response(200, [], json_encode([
            'suggestions' => [],
            'disabled' => true,
            'reason' => 'enterprise_policy',
        ]))]);

        $result = $client->conversations()->suggestReplies('conv_1');

        $this->assertSame([], $result['suggestions']);
        $this->assertTrue($result['disabled']);
        $this->assertSame('enterprise_policy', $result['reason']);
    }

    public function testListFiltersByLabel(): void
    {
        $client = $this->createMockClient([new Response(200, [], json_encode([
            'data' => [],
            'pagination' => ['total' => 0, 'limit' => 20, 'offset' => 0, 'hasMore' => false],
        ]))]);

        $client->conversations()->list(['label' => 'lbl_vip', 'status' => 'active']);

        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertStringEndsWith('/conversations', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame('lbl_vip', $query['label']);
        $this->assertSame('active', $query['status']);
    }

    public function testListWithoutALabelLeavesItOut(): void
    {
        $client = $this->createMockClient([new Response(200, [], json_encode([
            'data' => [],
            'pagination' => ['total' => 0, 'limit' => 20, 'offset' => 0, 'hasMore' => false],
        ]))]);

        $client->conversations()->list();

        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
        $this->assertArrayNotHasKey('label', $query);
    }
}
