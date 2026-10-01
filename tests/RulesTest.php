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

class RulesTest extends TestCase
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

    private function rawBody(int $index = 0): string
    {
        return (string) $this->history[$index]['request']->getBody();
    }

    public function testCreateSendsConditionsAndActionsAsObjects(): void
    {
        $client = $this->createMockClient([new Response(201, [], json_encode(['id' => 'rule_1']))]);

        $client->rules->create(
            'Support',
            ['intent' => ['support', 'billing'], 'intentConfidenceMin' => 0.8],
            ['addLabels' => ['lbl_1'], 'closeConversation' => true]
        );

        $this->assertSame(
            '{"name":"Support","conditions":{"intent":["support","billing"],"intentConfidenceMin":0.8},"actions":{"addLabels":["lbl_1"],"closeConversation":true}}',
            $this->rawBody()
        );
    }

    public function testCreateMergesAListOfConditionsAndActionsIntoOneObjectEach(): void
    {
        $client = $this->createMockClient([new Response(201, [], json_encode(['id' => 'rule_1']))]);

        $client->rules->create(
            'Support',
            [['intent' => 'support'], ['sentimentConfidenceMin' => 0.5]],
            [['addLabels' => ['lbl_1']]],
            ['priority' => 2]
        );

        $this->assertSame(
            '{"name":"Support","conditions":{"intent":"support","sentimentConfidenceMin":0.5},"actions":{"addLabels":["lbl_1"]},"priority":2}',
            $this->rawBody()
        );
    }

    public function testUpdateMergesAListOfConditionsAndActionsIntoOneObjectEach(): void
    {
        $client = $this->createMockClient([new Response(200, [], json_encode(['id' => 'rule_1']))]);

        $client->rules->update('rule_1', [
            'conditions' => [['sentiment' => 'negative']],
            'actions' => [['addLabels' => ['lbl_2']], ['closeConversation' => true]],
        ]);

        $this->assertSame('PATCH', $this->history[0]['request']->getMethod());
        $this->assertSame(
            '{"conditions":{"sentiment":"negative"},"actions":{"addLabels":["lbl_2"],"closeConversation":true}}',
            $this->rawBody()
        );
    }

    public function testUpdateLeavesOtherFieldsAsTheyAre(): void
    {
        $client = $this->createMockClient([new Response(200, [], json_encode(['id' => 'rule_1']))]);

        $client->rules->update('rule_1', ['name' => 'Renamed', 'priority' => 3]);

        $this->assertSame('{"name":"Renamed","priority":3}', $this->rawBody());
    }
}
