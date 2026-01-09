<?php

declare(strict_types=1);

namespace Sendly;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Represents a webhook configuration
 */
class Webhook
{
    public const EVENT_MESSAGE_SENT = 'message.sent';
    public const EVENT_MESSAGE_DELIVERED = 'message.delivered';
    public const EVENT_MESSAGE_FAILED = 'message.failed';
    public const EVENT_MESSAGE_BOUNCED = 'message.bounced';
    public const EVENT_MESSAGE_RECEIVED = 'message.received';

    public const CIRCUIT_STATE_CLOSED = 'closed';
    public const CIRCUIT_STATE_OPEN = 'open';
    public const CIRCUIT_STATE_HALF_OPEN = 'half_open';

    public const MODE_ALL = 'all';
    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';

    public readonly string $id;
    public readonly string $url;
    /** @var array<string> */
    public readonly array $events;
    public readonly string $mode;
    public readonly bool $isActive;
    public readonly int $failureCount;
    public readonly string $circuitState;
    public readonly ?string $apiVersion;
    public readonly int $totalDeliveries;
    public readonly int $successfulDeliveries;
    public readonly float $successRate;
    public readonly ?DateTimeImmutable $lastDeliveryAt;
    public readonly DateTimeImmutable $createdAt;
    public readonly DateTimeImmutable $updatedAt;

    /**
     * Create a Webhook from API response data
     *
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? '';
        $this->url = $data['url'] ?? '';
        $this->events = $data['events'] ?? [];
        $this->mode = $data['mode'] ?? self::MODE_ALL;
        $this->isActive = (bool) ($data['is_active'] ?? $data['isActive'] ?? true);
        $this->failureCount = (int) ($data['failure_count'] ?? $data['failureCount'] ?? 0);
        $this->circuitState = $data['circuit_state'] ?? $data['circuitState'] ?? self::CIRCUIT_STATE_CLOSED;
        $this->apiVersion = $data['api_version'] ?? $data['apiVersion'] ?? null;
        $this->totalDeliveries = (int) ($data['total_deliveries'] ?? $data['totalDeliveries'] ?? 0);
        $this->successfulDeliveries = (int) ($data['successful_deliveries'] ?? $data['successfulDeliveries'] ?? 0);
        $this->successRate = (float) ($data['success_rate'] ?? $data['successRate'] ?? 0.0);
        $this->lastDeliveryAt = $this->parseDateTime($data['last_delivery_at'] ?? $data['lastDeliveryAt'] ?? null);
        $this->createdAt = $this->parseDateTime($data['created_at'] ?? $data['createdAt'] ?? null) ?? new DateTimeImmutable();
        $this->updatedAt = $this->parseDateTime($data['updated_at'] ?? $data['updatedAt'] ?? null) ?? new DateTimeImmutable();
    }

    /**
     * Check if webhook is healthy (circuit closed)
     */
    public function isHealthy(): bool
    {
        return $this->isActive && $this->circuitState === self::CIRCUIT_STATE_CLOSED;
    }

    /**
     * Check if circuit breaker is open
     */
    public function isCircuitOpen(): bool
    {
        return $this->circuitState === self::CIRCUIT_STATE_OPEN;
    }

    /**
     * Convert to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'events' => $this->events,
            'mode' => $this->mode,
            'is_active' => $this->isActive,
            'failure_count' => $this->failureCount,
            'circuit_state' => $this->circuitState,
            'api_version' => $this->apiVersion,
            'total_deliveries' => $this->totalDeliveries,
            'successful_deliveries' => $this->successfulDeliveries,
            'success_rate' => $this->successRate,
            'last_delivery_at' => $this->lastDeliveryAt?->format(DateTimeInterface::ATOM),
            'created_at' => $this->createdAt->format(DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Parse a datetime string
     */
    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}

/**
 * Response from creating a webhook (includes secret)
 */
class WebhookCreatedResponse
{
    public readonly Webhook $webhook;
    public readonly string $secret;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $webhookData = $data['webhook'] ?? $data;
        $this->webhook = new Webhook($webhookData);
        $this->secret = $data['secret'] ?? '';
    }
}

/**
 * Represents a webhook delivery attempt
 */
class WebhookDelivery
{
    public readonly string $id;
    public readonly string $webhookId;
    public readonly string $eventType;
    public readonly int $httpStatus;
    public readonly bool $success;
    public readonly int $attemptNumber;
    public readonly ?string $errorMessage;
    public readonly int $responseTimeMs;
    public readonly DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? '';
        $this->webhookId = $data['webhook_id'] ?? $data['webhookId'] ?? '';
        $this->eventType = $data['event_type'] ?? $data['eventType'] ?? '';
        $this->httpStatus = (int) ($data['http_status'] ?? $data['httpStatus'] ?? 0);
        $this->success = (bool) ($data['success'] ?? false);
        $this->attemptNumber = (int) ($data['attempt_number'] ?? $data['attemptNumber'] ?? 1);
        $this->errorMessage = $data['error_message'] ?? $data['errorMessage'] ?? null;
        $this->responseTimeMs = (int) ($data['response_time_ms'] ?? $data['responseTimeMs'] ?? 0);
        $this->createdAt = $this->parseDateTime($data['created_at'] ?? $data['createdAt'] ?? null) ?? new DateTimeImmutable();
    }

    /**
     * Parse a datetime string
     */
    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}

/**
 * Result from testing a webhook
 */
class WebhookTestResult
{
    public readonly bool $success;
    public readonly int $statusCode;
    public readonly int $responseTimeMs;
    public readonly ?string $error;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->success = (bool) ($data['success'] ?? false);
        $this->statusCode = (int) ($data['status_code'] ?? $data['statusCode'] ?? 0);
        $this->responseTimeMs = (int) ($data['response_time_ms'] ?? $data['responseTimeMs'] ?? 0);
        $this->error = $data['error'] ?? null;
    }
}

/**
 * Response from rotating a webhook secret
 */
class WebhookSecretRotation
{
    public readonly string $secret;
    public readonly DateTimeImmutable $rotatedAt;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->secret = $data['secret'] ?? '';
        $this->rotatedAt = $this->parseDateTime($data['rotated_at'] ?? $data['rotatedAt'] ?? null) ?? new DateTimeImmutable();
    }

    /**
     * Parse a datetime string
     */
    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
