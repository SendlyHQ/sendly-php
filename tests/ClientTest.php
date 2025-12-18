<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\TestCase;
use Sendly\Sendly;
use Sendly\Resources\Messages;
use Sendly\Exceptions\AuthenticationException;

/**
 * Tests for Sendly client initialization
 */
class ClientTest extends TestCase
{
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

    public function testClientConstants(): void
    {
        $this->assertSame('2.1.0', Sendly::VERSION);
        $this->assertSame('https://sendly.live/api/v1', Sendly::DEFAULT_BASE_URL);
        $this->assertSame(30, Sendly::DEFAULT_TIMEOUT);
    }
}
