<?php

declare(strict_types=1);

namespace Sendly;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Sendly\Resources\Messages;
use Sendly\Resources\Webhooks;
use Sendly\Resources\Account;
use Sendly\Resources\Verify;
use Sendly\Resources\Templates;
use Sendly\Exceptions\SendlyException;
use Sendly\Exceptions\AuthenticationException;
use Sendly\Exceptions\RateLimitException;
use Sendly\Exceptions\InsufficientCreditsException;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\NotFoundException;
use Sendly\Exceptions\NetworkException;

/**
 * Sendly API Client
 *
 * Official PHP SDK for the Sendly SMS API.
 *
 * @package Sendly
 */
class Sendly
{
    public const VERSION = '1.0.5';
    public const DEFAULT_BASE_URL = 'https://sendly.live/api/v1';
    public const DEFAULT_TIMEOUT = 30;

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;
    private GuzzleClient $httpClient;
    private Messages $messages;
    private Webhooks $webhooks;
    private Account $account;
    private Verify $verify;
    private Templates $templates;

    /**
     * Create a new Sendly client
     *
     * @param string $apiKey Your Sendly API key
     * @param array{baseUrl?: string, timeout?: int, maxRetries?: int} $options Configuration options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if (empty($apiKey)) {
            throw new AuthenticationException('API key is required');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = $options['baseUrl'] ?? self::DEFAULT_BASE_URL;
        $this->timeout = $options['timeout'] ?? self::DEFAULT_TIMEOUT;
        $this->maxRetries = $options['maxRetries'] ?? 3;

        $this->httpClient = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'sendly-php/' . self::VERSION,
            ],
        ]);

        $this->messages = new Messages($this);
        $this->webhooks = new Webhooks($this);
        $this->account = new Account($this);
        $this->verify = new Verify($this);
        $this->templates = new Templates($this);
    }

    /**
     * Get the Messages resource
     *
     * @return Messages
     */
    public function messages(): Messages
    {
        return $this->messages;
    }

    /**
     * Get the Webhooks resource
     *
     * @return Webhooks
     */
    public function webhooks(): Webhooks
    {
        return $this->webhooks;
    }

    /**
     * Get the Account resource
     *
     * @return Account
     */
    public function account(): Account
    {
        return $this->account;
    }

    /**
     * Get the Verify resource
     *
     * @return Verify
     */
    public function verify(): Verify
    {
        return $this->verify;
    }

    /**
     * Get the Templates resource
     *
     * @return Templates
     */
    public function templates(): Templates
    {
        return $this->templates;
    }

    /**
     * Make a GET request
     *
     * @param string $path API endpoint path
     * @param array<string, mixed> $params Query parameters
     * @return array<string, mixed> Response data
     * @throws SendlyException
     */
    public function get(string $path, array $params = []): array
    {
        return $this->request('GET', $path, ['query' => $params]);
    }

    /**
     * Make a POST request
     *
     * @param string $path API endpoint path
     * @param array<string, mixed> $body Request body
     * @return array<string, mixed> Response data
     * @throws SendlyException
     */
    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, ['json' => $body]);
    }

    /**
     * Make a PATCH request
     *
     * @param string $path API endpoint path
     * @param array<string, mixed> $body Request body
     * @return array<string, mixed> Response data
     * @throws SendlyException
     */
    public function patch(string $path, array $body = []): array
    {
        return $this->request('PATCH', $path, ['json' => $body]);
    }

    /**
     * Make a PUT request
     *
     * @param string $path API endpoint path
     * @param array<string, mixed> $body Request body
     * @return array<string, mixed> Response data
     * @throws SendlyException
     */
    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, ['json' => $body]);
    }

    /**
     * Make a DELETE request
     *
     * @param string $path API endpoint path
     * @return array<string, mixed> Response data
     * @throws SendlyException
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Make an HTTP request with retries
     *
     * @param string $method HTTP method
     * @param string $path API endpoint path
     * @param array<string, mixed> $options Request options
     * @return array<string, mixed> Response data
     * @throws SendlyException
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                $delay = (int) pow(2, $attempt - 1) * 1000000; // Exponential backoff in microseconds
                usleep($delay);
            }

            try {
                $response = $this->httpClient->request($method, $path, $options);
                $body = (string) $response->getBody();

                return json_decode($body, true) ?? [];
            } catch (ConnectException $e) {
                $lastException = new NetworkException(
                    'Connection failed: ' . $e->getMessage(),
                    0,
                    $e
                );
            } catch (RequestException $e) {
                $lastException = $this->handleRequestException($e);

                // Don't retry certain errors - throw immediately
                if ($lastException instanceof AuthenticationException ||
                    $lastException instanceof ValidationException ||
                    $lastException instanceof NotFoundException ||
                    $lastException instanceof InsufficientCreditsException ||
                    $lastException instanceof RateLimitException) {
                    throw $lastException;
                }
            }
        }

        throw $lastException ?? new SendlyException('Request failed after retries');
    }

    /**
     * Handle request exceptions and convert to typed errors
     *
     * @param RequestException $e The request exception
     * @return SendlyException The typed exception
     */
    private function handleRequestException(RequestException $e): SendlyException
    {
        $response = $e->getResponse();

        if ($response === null) {
            return new NetworkException('Request failed: ' . $e->getMessage());
        }

        $statusCode = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true) ?? [];
        $message = $body['message'] ?? $body['error'] ?? 'Unknown error';

        return match ($statusCode) {
            401 => new AuthenticationException($message),
            402 => new InsufficientCreditsException($message),
            404 => new NotFoundException($message),
            429 => new RateLimitException(
                $message,
                (int) ($response->getHeader('Retry-After')[0] ?? 0)
            ),
            400, 422 => new ValidationException($message, $body['details'] ?? null),
            default => new SendlyException($message, $statusCode),
        };
    }
}
