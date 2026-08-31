<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ReflectionProperty;
use Sendly\Sendly;
use Sendly\Resources\Messages;
use Sendly\Exceptions\AuthenticationException;

/**
 * Tests for Sendly client initialization
 */
class ClientTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    public function testClientInitializationWithValidApiKey(): void
    {
        $client = new Sendly('test_api_key_123');

        $this->assertInstanceOf(Sendly::class, $client);
        $this->assertInstanceOf(Messages::class, $client->messages());
    }

    public function testClientInitializationWithEmptyApiKey(): void
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('API key is required');

        new Sendly('');
    }

    public function testClientWithCustomBaseUrl(): void
    {
        $client = new Sendly('test_api_key', [
            'baseUrl' => 'https://custom.example.com/api',
        ]);

        $this->assertInstanceOf(Sendly::class, $client);
    }

    public function testClientWithCustomTimeout(): void
    {
        $client = new Sendly('test_api_key', [
            'timeout' => 60,
        ]);

        $this->assertInstanceOf(Sendly::class, $client);
    }

    public function testClientWithCustomMaxRetries(): void
    {
        $client = new Sendly('test_api_key', [
            'maxRetries' => 5,
        ]);

        $this->assertInstanceOf(Sendly::class, $client);
    }

    public function testClientWithAllCustomOptions(): void
    {
        $client = new Sendly('test_api_key', [
            'baseUrl' => 'https://custom.example.com/api',
            'timeout' => 60,
            'maxRetries' => 5,
        ]);

        $this->assertInstanceOf(Sendly::class, $client);
        $this->assertInstanceOf(Messages::class, $client->messages());
    }

    public function testMessagesResourceAccessor(): void
    {
        $client = new Sendly('test_api_key');
        $messages = $client->messages();

        $this->assertInstanceOf(Messages::class, $messages);
        // Calling messages() multiple times should return the same instance
        $this->assertSame($messages, $client->messages());
    }

    public function testRequestsResolveUnderTheVersionedApiBase(): void
    {
        $client = new Sendly('test_api_key');
        $this->interceptRequests($client);

        $client->messages->send(['to' => '+15551234567', 'text' => 'Hello!']);

        $this->assertSame(
            'https://sendly.live/api/v1/messages',
            (string) $this->history[0]['request']->getUri()
        );
    }

    public function testFullyVersionedPathIsNotPrefixedTwice(): void
    {
        // Callers reaching endpoints with no resource method write the full
        // versioned path themselves; prefixing the base again would 404 them.
        $client = new Sendly('test_api_key');
        $this->interceptRequests($client);

        $client->get('/api/v1/conversations', ['limit' => 100]);

        $this->assertSame(
            'https://sendly.live/api/v1/conversations?limit=100',
            (string) $this->history[0]['request']->getUri()
        );
    }

    public function testCustomBaseUrlWithTrailingSlashDoesNotDoubleUp(): void
    {
        $client = new Sendly('test_api_key', ['baseUrl' => 'https://custom.example.com/api/v1/']);
        $this->interceptRequests($client);

        $client->messages->list(['limit' => 2]);

        $this->assertSame(
            'https://custom.example.com/api/v1/messages?limit=2&offset=0',
            (string) $this->history[0]['request']->getUri()
        );
    }

    /**
     * Swap the handler on the client's own Guzzle stack so the request URL the
     * SDK builds is observed exactly as it would go on the wire.
     */
    private function interceptRequests(Sendly $client): void
    {
        $this->history = [];
        $httpClient = (new ReflectionProperty(Sendly::class, 'httpClient'))->getValue($client);
        $config = (new ReflectionProperty(GuzzleClient::class, 'config'))->getValue($httpClient);
        $stack = $config['handler'];
        $stack->setHandler(new MockHandler([
            new Response(200, [], json_encode(['messages' => [], 'message' => ['id' => 'msg_123']])),
        ]));
        $stack->push(Middleware::history($this->history));
    }

    public function testClientConstants(): void
    {
        $this->assertSame('3.37.1', Sendly::VERSION);
        $this->assertSame('https://sendly.live/api/v1', Sendly::DEFAULT_BASE_URL);
        $this->assertSame(30, Sendly::DEFAULT_TIMEOUT);
    }
}
