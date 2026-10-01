<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Generator;
use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

class Campaigns
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    /** What a sent campaign's status becomes */
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    /** Never returned: a sent campaign is 'completed'. As a list() filter it matches completed campaigns. */
    public const STATUS_SENT = 'sent';
    /** @deprecated Never returned: campaigns cannot be paused. */
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CANCELLED = 'cancelled';

    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List campaigns
     *
     * @param array{limit?: int, offset?: int, status?: string} $options Query options
     * @return array{campaigns: array<array<string, mixed>>, total?: int, limit?: int, offset?: int}
     */
    public function list(array $options = []): array
    {
        $params = array_filter([
            'limit' => min($options['limit'] ?? 20, 100),
            'offset' => $options['offset'] ?? 0,
            'status' => $options['status'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->get('/campaigns', $params);
    }

    /**
     * Get a campaign by ID
     *
     * @param string $id Campaign ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function get(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        return $this->client->get("/campaigns/" . rawurlencode($id));
    }

    /**
     * Create a new campaign
     *
     * A campaign targets one contact list: pass `contactListId`, or a single ID
     * in `contactListIds` (the API answers 400 to more than one). The API sends
     * every campaign as a marketing message, subject to quiet hours, and has no
     * segments: `segmentId` and `messageType` have no effect.
     *
     * @param string $name Campaign name
     * @param string $text Message content
     * @param array{contactListId?: string, contactListIds?: array<string>, segmentId?: string, messageType?: string} $options Additional options.
     *   `segmentId` and `messageType` are deprecated and have no effect.
     * @return array<string, mixed>
     * @throws ValidationException If parameters are invalid
     */
    public function create(string $name, string $text, array $options = []): array
    {
        if (empty($name)) {
            throw new ValidationException('Campaign name is required');
        }

        if (empty($text)) {
            throw new ValidationException('Campaign text is required');
        }

        if ((preg_match_all('/./su', $text) ?: strlen($text)) > 1600) {
            throw new ValidationException('Campaign text exceeds maximum length (1600 characters)');
        }

        $this->validateMessageType($options['messageType'] ?? null);

        $data = array_merge([
            'name' => $name,
            'text' => $text,
        ], $options);

        return $this->client->post('/campaigns', $data);
    }

    /**
     * Update a campaign
     *
     * Only a draft or scheduled campaign can be edited. As with create(),
     * `segmentId` and `messageType` have no effect.
     *
     * @param string $id Campaign ID
     * @param array{name?: string, text?: string, contactListId?: string, contactListIds?: array<string>, segmentId?: string, messageType?: string} $data Update data.
     *   `segmentId` and `messageType` are deprecated and have no effect.
     * @return array<string, mixed>
     * @throws ValidationException If parameters are invalid
     */
    public function update(string $id, array $data): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        if (isset($data['text']) && (preg_match_all('/./su', $data['text']) ?: strlen($data['text'])) > 1600) {
            throw new ValidationException('Campaign text exceeds maximum length (1600 characters)');
        }

        $this->validateMessageType($data['messageType'] ?? null);

        return $this->client->patch("/campaigns/" . rawurlencode($id), $data);
    }

    /**
     * Delete a campaign
     *
     * @param string $id Campaign ID
     * @return array{success: bool, message?: string}
     * @throws ValidationException If ID is empty
     */
    public function delete(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        return $this->client->delete("/campaigns/" . rawurlencode($id)) ?: ['success' => true];
    }

    /**
     * Preview campaign before sending
     *
     * @param string $id Campaign ID
     * @return array{recipientCount: int, estimatedCost: float, estimatedSegments: int, sampleRecipients?: array<string>}
     * @throws ValidationException If ID is empty
     */
    public function preview(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        return $this->client->get("/campaigns/" . rawurlencode($id) . "/preview");
    }

    /**
     * Send a campaign immediately
     *
     * @param string $id Campaign ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function send(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        return $this->client->post("/campaigns/" . rawurlencode($id) . "/send");
    }

    /**
     * Schedule a campaign for future delivery
     *
     * @param string $id Campaign ID
     * @param string $scheduledAt ISO 8601 datetime for when to send
     * @return array<string, mixed>
     * @throws ValidationException If parameters are invalid
     */
    public function schedule(string $id, string $scheduledAt): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        if (empty($scheduledAt)) {
            throw new ValidationException('Scheduled time is required');
        }

        return $this->client->post("/campaigns/" . rawurlencode($id) . "/schedule", [
            'scheduledAt' => $scheduledAt,
        ]);
    }

    /**
     * Cancel a scheduled campaign
     *
     * @param string $id Campaign ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function cancel(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        return $this->client->post("/campaigns/" . rawurlencode($id) . "/cancel");
    }

    /**
     * Pause a sending campaign
     *
     * @deprecated The API has no campaign pause route, so this call fails with a 404. There is no replacement.
     *
     * @param string $id Campaign ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function pause(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        return $this->client->post("/campaigns/" . rawurlencode($id) . "/pause");
    }

    /**
     * Resume a paused campaign
     *
     * @deprecated The API has no campaign resume route, so this call fails with a 404. There is no replacement.
     *
     * @param string $id Campaign ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function resume(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        return $this->client->post("/campaigns/" . rawurlencode($id) . "/resume");
    }

    /**
     * Clone an existing campaign
     *
     * @param string $id Campaign ID to clone
     * @param string|null $name Optional new name for the cloned campaign
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function clone(string $id, ?string $name = null): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        $data = [];
        if ($name !== null) {
            $data['name'] = $name;
        }

        return $this->client->post("/campaigns/" . rawurlencode($id) . "/clone", $data);
    }

    /**
     * Get campaign statistics
     *
     * @deprecated The API has no campaign stats route, so this call fails with a 404. Read the campaign with get() instead.
     *
     * @param string $id Campaign ID
     * @return array{sent: int, delivered: int, failed: int, pending: int, deliveryRate?: float}
     * @throws ValidationException If ID is empty
     */
    public function stats(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        return $this->client->get("/campaigns/" . rawurlencode($id) . "/stats");
    }

    /**
     * Get campaign recipients with their delivery status
     *
     * @deprecated The API has no campaign recipients route, so this call fails with a 404. There is no replacement.
     *
     * @param string $id Campaign ID
     * @param array{limit?: int, offset?: int, status?: string} $options Query options
     * @return array{recipients: array<array<string, mixed>>, pagination?: array<string, mixed>}
     * @throws ValidationException If ID is empty
     */
    public function recipients(string $id, array $options = []): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        $params = array_filter([
            'limit' => min($options['limit'] ?? 20, 100),
            'offset' => $options['offset'] ?? 0,
            'status' => $options['status'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->get("/campaigns/" . rawurlencode($id) . "/recipients", $params);
    }

    /**
     * Iterate over all campaigns with automatic pagination
     *
     * @param array{status?: string, batchSize?: int} $options Query options.
     *   `batchSize` is the page size, 1 to 100 (default 100).
     * @return Generator<int, array<string, mixed>>
     */
    public function each(array $options = []): Generator
    {
        $batchSize = max(1, min((int) ($options['batchSize'] ?? 100), 100));
        $offset = 0;

        do {
            $response = $this->list([
                'limit' => $batchSize,
                'offset' => $offset,
                'status' => $options['status'] ?? null,
            ]);

            $campaigns = $response['campaigns'] ?? $response['data'] ?? [];

            foreach ($campaigns as $campaign) {
                yield $campaign;
            }

            $offset += count($campaigns);
            $hasMore = isset($response['total'])
                ? $offset < (int) $response['total']
                : count($campaigns) === $batchSize;
        } while ($hasMore && count($campaigns) > 0);
    }

    /**
     * Validate message type
     *
     * @throws ValidationException
     */
    private function validateMessageType(?string $messageType): void
    {
        if ($messageType !== null && !in_array($messageType, ['marketing', 'transactional'], true)) {
            throw new ValidationException(
                "Invalid message type: '{$messageType}'. Must be 'marketing' or 'transactional'"
            );
        }
    }
}
