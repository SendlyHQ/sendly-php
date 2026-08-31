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
use Sendly\Resources\Campaigns;
use Sendly\Resources\Contacts;
use Sendly\Resources\Media;
use Sendly\Resources\Enterprise;
use Sendly\Resources\Conversations;
use Sendly\Resources\Labels;
use Sendly\Resources\Drafts;
use Sendly\Resources\Rules;
use Sendly\Resources\BusinessUpgrade;
use Sendly\Resources\Numbers;
use Sendly\Resources\TenDlc;
use Sendly\Resources\Links;
use Sendly\Resources\WhatsApp;
use Sendly\Resources\Rcs;
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
    public const VERSION = '3.38.0';
    public const DEFAULT_BASE_URL = 'https://sendly.live/api/v1';
    public const DEFAULT_TIMEOUT = 30;

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;
    private string $organizationId;
    private GuzzleClient $httpClient;

    // Resource properties exposed as public so users can access them
    // directly (e.g. $client->messages->send(...)) matching the idiom of
    // our Node/Python/Ruby SDKs and our published documentation. The
    // legacy method-style accessors below (messages(), webhooks(), …) are
    // retained so existing v1.0.5 consumers continue to work unchanged.
    public Messages $messages;
    public Webhooks $webhooks;
    public Account $account;
    public Verify $verify;
    public Templates $templates;
    public Campaigns $campaigns;
    public Contacts $contacts;
    public Media $media;
    public Enterprise $enterprise;
    public Conversations $conversations;
    public Labels $labels;
    public Drafts $drafts;
    public Rules $rules;
    public BusinessUpgrade $businessUpgrade;
    public Numbers $numbers;
    public TenDlc $tenDlc;
    public Links $links;
    public WhatsApp $whatsapp;
    public Rcs $rcs;

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
        $this->organizationId = $options['organization_id'] ?? getenv('SENDLY_ORG_ID') ?: '';

        $this->buildHttpClient();

        $this->messages = new Messages($this);
        $this->webhooks = new Webhooks($this);
        $this->account = new Account($this);
        $this->verify = new Verify($this);
        $this->templates = new Templates($this);
        $this->campaigns = new Campaigns($this);
        $this->contacts = new Contacts($this);
        $this->media = new Media($this);
        $this->enterprise = new Enterprise($this);
        $this->conversations = new Conversations($this);
        $this->labels = new Labels($this);
        $this->drafts = new Drafts($this);
        $this->rules = new Rules($this);
        $this->businessUpgrade = new BusinessUpgrade($this);
        $this->numbers = new Numbers($this);
        $this->tenDlc = new TenDlc($this);
        $this->links = new Links($this);
        $this->whatsapp = new WhatsApp($this);
        $this->rcs = new Rcs($this);
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
     * Get the Campaigns resource
     *
     * @return Campaigns
     */
    public function campaigns(): Campaigns
    {
        return $this->campaigns;
    }

    /**
     * Get the Contacts resource
     *
     * @return Contacts
     */
    public function contacts(): Contacts
    {
        return $this->contacts;
    }

    /**
     * Get the Media resource
     *
     * @return Media
     */
    public function media(): Media
    {
        return $this->media;
    }

    /**
     * Get the Enterprise resource
     *
     * @return Enterprise
     */
    public function enterprise(): Enterprise
    {
        return $this->enterprise;
    }

    /**
     * Get the Conversations resource
     *
     * @return Conversations
     */
    public function conversations(): Conversations
    {
        return $this->conversations;
    }

    /**
     * Get the Labels resource
     *
     * @return Labels
     */
    public function labels(): Labels
    {
        return $this->labels;
    }

    /**
     * Get the Drafts resource
     *
     * @return Drafts
     */
    public function drafts(): Drafts
    {
        return $this->drafts;
    }

    /**
     * Get the Rules resource
     *
     * @return Rules
     */
    public function rules(): Rules
    {
        return $this->rules;
    }

    /**
     * Get the BusinessUpgrade resource
     *
     * @return BusinessUpgrade
     */
    public function businessUpgrade(): BusinessUpgrade
    {
        return $this->businessUpgrade;
    }

    /**
     * Get the Numbers resource
     *
     * @return Numbers
     */
    public function numbers(): Numbers
    {
        return $this->numbers;
    }

    /**
     * Get the TenDlc resource
     *
     * @return TenDlc
     */
    public function tenDlc(): TenDlc
    {
        return $this->tenDlc;
    }

    /**
     * Get the Links resource
     *
     * @return Links
     */
    public function links(): Links
    {
        return $this->links;
    }

    /**
     * Get the WhatsApp resource
     *
     * @return WhatsApp
     */
    public function whatsapp(): WhatsApp
    {
        return $this->whatsapp;
    }

    /**
     * Get the RCS resource
     *
     * @return Rcs
     */
    public function rcs(): Rcs
    {
        return $this->rcs;
    }

    /**
     * Get the configured API base URL (e.g. https://sendly.live/api/v1)
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setOrganizationId(string $id): void
    {
        $this->organizationId = $id;
        $this->buildHttpClient();
    }

    private function buildHttpClient(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'sendly-php/' . self::VERSION,
        ];

        if ($this->organizationId !== '') {
            $headers['X-Organization-Id'] = $this->organizationId;
        }

        $this->httpClient = new GuzzleClient([
            'base_uri' => $this->apiBase(),
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
            'headers' => $headers,
        ]);
    }

    /**
     * The API base with exactly one trailing slash. RFC 3986 only merges a
     * relative reference onto a base whose path ends in a slash, so this form
     * is what keeps the version segment of `/api/v1` intact.
     *
     * @return string
     */
    private function apiBase(): string
    {
        return rtrim($this->baseUrl, '/') . '/';
    }

    /**
     * Resolve an endpoint path (written absolute, e.g. `/messages`) against the
     * API base. An absolute-path reference replaces the base path entirely, so
     * the URL is composed here rather than left to base_uri resolution.
     *
     * @param string $path API endpoint path
     * @return string Fully-qualified request URL
     */
    private function buildUrl(string $path): string
    {
        $basePath = rtrim(parse_url($this->apiBase(), PHP_URL_PATH) ?? '', '/');
        $normalised = '/' . ltrim($path, '/');

        // Callers who already write the full versioned path (a documented way
        // to reach endpoints this SDK has no resource method for) must not
        // have the base prefixed a second time.
        if ($basePath !== '' && (
            $normalised === $basePath || str_starts_with($normalised, $basePath . '/')
        )) {
            return rtrim($this->apiBase(), '/') . substr($normalised, strlen($basePath));
        }

        return $this->apiBase() . ltrim($path, '/');
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
     * @param string|null $idempotencyKey Caller-supplied idempotency key (1-255 printable
     *   ASCII characters). When null, a unique key is generated automatically and reused
     *   across retry attempts so the server can dedupe a retried POST that already
     *   reached it. Supply your own key to extend that protection across process restarts.
     * @param bool $autoIdempotencyKey Set to false to skip auto-generating a key for this
     *   POST. Used for the batch endpoint, where the server dedupes header-less retries
     *   by request content and an auto key would bypass that net. A caller-supplied
     *   $idempotencyKey is always sent regardless.
     * @return array<string, mixed> Response data
     * @throws SendlyException
     */
    public function post(string $path, array $body = [], ?string $idempotencyKey = null, bool $autoIdempotencyKey = true): array
    {
        return $this->request('POST', $path, ['json' => $body], $idempotencyKey, $autoIdempotencyKey);
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
     * Make a POST request with multipart form data
     *
     * @param string $path API endpoint path
     * @param array<array{name: string, contents: mixed, filename?: string, headers?: array<string, string>}> $multipart Multipart form fields
     * @param string|null $idempotencyKey Caller-supplied idempotency key (1-255 printable
     *   ASCII characters). Auto-generated when null, with the same retry semantics as post().
     * @return array<string, mixed> Response data
     * @throws SendlyException
     */
    public function postMultipart(string $path, array $multipart, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', $path, ['multipart' => $multipart], $idempotencyKey);
    }

    /**
     * Make an HTTP request with retries
     *
     * @param string $method HTTP method
     * @param string $path API endpoint path
     * @param array<string, mixed> $options Request options
     * @param string|null $idempotencyKey Caller-supplied idempotency key, sent verbatim and never rotated
     * @param bool $autoIdempotencyKey Whether to auto-generate a key for a POST when none is supplied
     * @return array<string, mixed> Response data
     * @throws SendlyException
     */
    private function request(string $method, string $path, array $options = [], ?string $idempotencyKey = null, bool $autoIdempotencyKey = true): array
    {
        $explicitKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $key = $explicitKey;
        if ($key === null && $method === 'POST' && $autoIdempotencyKey) {
            $key = $this->generateIdempotencyKey();
        }

        $lastException = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                $delay = (int) pow(2, $attempt - 1) * 1000000; // Exponential backoff in microseconds
                usleep($delay);
            }

            if ($key !== null) {
                $options['headers']['Idempotency-Key'] = $key;
            }

            try {
                $response = $this->httpClient->request($method, $this->buildUrl($path), $options);
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

                // A 5xx means the server responded (and may have cached that
                // response under the key), so an auto-generated key is rotated
                // to let the retry re-execute. Connection failures leave the
                // outcome unknown - the key is kept so the server can dedupe a
                // request that actually went through. Caller-supplied keys are
                // never rotated.
                if ($explicitKey === null &&
                    $key !== null &&
                    $attempt < $this->maxRetries &&
                    $lastException->getCode() >= 500) {
                    $key = $this->generateIdempotencyKey();
                }
            }
        }

        throw $lastException ?? new SendlyException('Request failed after retries');
    }

    /**
     * Generate an idempotency key for a logical request. Reused across retry
     * attempts so the server can recognize a retry of a POST that already
     * reached it.
     */
    private function generateIdempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            'sendly-php-retry-%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Validate and normalize a caller-supplied idempotency key. Empty and
     * whitespace-only values are treated as absent (auto-generation still
     * applies); invalid values fail fast before any network call.
     *
     * @throws ValidationException
     */
    private function normalizeIdempotencyKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $trimmed = trim($key);
        if ($trimmed === '') {
            return null;
        }

        if (strlen($trimmed) > 255 || !preg_match('/^[\x20-\x7E]+$/', $trimmed)) {
            throw new ValidationException('Idempotency key must be 1-255 printable ASCII characters');
        }

        return $trimmed;
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
