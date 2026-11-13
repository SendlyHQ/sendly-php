<?php

declare(strict_types=1);

namespace Sendly;

/**
 * What a webhook recovery would do for a window, without sending anything.
 *
 * Returned by `webhooks()->recoveryPreview()`. The API sends its keys in
 * camelCase, unlike the other webhook responses, and the nested arrays keep
 * them. Count maps keep the API's keys, such as 'failed' or
 * 'message.delivered'.
 */
class WebhookRecoveryPreview
{
    /** 'closed', 'open' or 'half_open'; values the API adds later come through unchanged */
    public readonly string $circuitState;
    /** When the circuit opened (ISO 8601), or null */
    public readonly ?string $circuitOpenedAt;
    /** Failed deliveries in the current streak */
    public readonly int $failureCount;
    /**
     * Failed and cancelled deliveries in the window: what redeliver() would send
     *
     * @var array{total: int, byStatus: array<string, int>, byEventType: array<string, int>}
     */
    public readonly array $auditFailures;
    /**
     * Message events with no delivery in the window: what backfill() would send
     *
     * @var array{total: int, byEventType: array<string, int>}
     */
    public readonly array $missingFromMessages;
    /**
     * The window in up to 24 equal buckets, oldest first
     *
     * @var array<int, array{bucket: string, missing: int, failed: int}>
     */
    public readonly array $histogram;
    /**
     * Up to 5 affected events: failed deliveries first, then missing message events
     *
     * @var array<int, array{id: string, toMasked: string, eventType: string, occurredAt: string}>
     */
    public readonly array $samples;
    /**
     * The outage window, when the circuit has an opening time
     *
     * @var array{since: string, until: string}|null
     */
    public readonly ?array $windowSuggestion;
    /** The window's start (ISO 8601) */
    public readonly string $since;
    /** The window's end (ISO 8601) */
    public readonly string $until;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->circuitState = (string) ($data['circuitState'] ?? 'closed');
        $this->circuitOpenedAt = isset($data['circuitOpenedAt']) ? (string) $data['circuitOpenedAt'] : null;
        $this->failureCount = (int) ($data['failureCount'] ?? 0);

        $audit = is_array($data['auditFailures'] ?? null) ? $data['auditFailures'] : [];
        $this->auditFailures = [
            'total' => (int) ($audit['total'] ?? 0),
            'byStatus' => self::counts($audit['byStatus'] ?? null),
            'byEventType' => self::counts($audit['byEventType'] ?? null),
        ];

        $missing = is_array($data['missingFromMessages'] ?? null) ? $data['missingFromMessages'] : [];
        $this->missingFromMessages = [
            'total' => (int) ($missing['total'] ?? 0),
            'byEventType' => self::counts($missing['byEventType'] ?? null),
        ];

        $histogram = is_array($data['histogram'] ?? null) ? $data['histogram'] : [];
        $this->histogram = array_values(array_map(static fn (array $b) => [
            'bucket' => (string) ($b['bucket'] ?? ''),
            'missing' => (int) ($b['missing'] ?? 0),
            'failed' => (int) ($b['failed'] ?? 0),
        ], array_filter($histogram, 'is_array')));

        $samples = is_array($data['samples'] ?? null) ? $data['samples'] : [];
        $this->samples = array_values(array_map(static fn (array $s) => [
            'id' => (string) ($s['id'] ?? ''),
            'toMasked' => (string) ($s['toMasked'] ?? ''),
            'eventType' => (string) ($s['eventType'] ?? ''),
            'occurredAt' => (string) ($s['occurredAt'] ?? ''),
        ], array_filter($samples, 'is_array')));

        $suggestion = $data['windowSuggestion'] ?? null;
        $this->windowSuggestion = is_array($suggestion)
            ? ['since' => (string) ($suggestion['since'] ?? ''), 'until' => (string) ($suggestion['until'] ?? '')]
            : null;

        $this->since = (string) ($data['since'] ?? '');
        $this->until = (string) ($data['until'] ?? '');
    }

    /**
     * Check if the circuit breaker is open
     */
    public function isCircuitOpen(): bool
    {
        return $this->circuitState === 'open';
    }

    /**
     * @return array<string, int>
     */
    private static function counts(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $counts = [];
        foreach ($value as $key => $count) {
            $counts[(string) $key] = (int) $count;
        }
        return $counts;
    }
}
