<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

/**
 * Drafts resource for managing message drafts
 */
class Drafts
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Create a new draft
     *
     * @param string $conversationId Conversation ID
     * @param string $text Draft text
     * @param array{mediaUrls?: array<string>, metadata?: array<string, mixed>, source?: string} $options Additional options
     * @return array<string, mixed>
     * @throws ValidationException If parameters are invalid
     */
    public function create(string $conversationId, string $text, array $options = []): array
    {
        if (empty($conversationId)) {
            throw new ValidationException('Conversation ID is required');
        }

        if (empty($text)) {
            throw new ValidationException('Draft text is required');
        }

        $payload = [
            'conversationId' => $conversationId,
            'text' => $text,
        ];

        if (isset($options['mediaUrls'])) {
            $payload['mediaUrls'] = $options['mediaUrls'];
        }

        if (isset($options['metadata'])) {
            $payload['metadata'] = $options['metadata'];
        }

        if (isset($options['source'])) {
            $payload['source'] = $options['source'];
        }

        return $this->client->post('/drafts', $payload);
    }

    /**
     * List drafts
     *
     * @param array{conversation_id?: string, status?: string, limit?: int, offset?: int} $options Query options
     * @return array{data: array<array<string, mixed>>, pagination: array{total: int, limit: int, offset: int, hasMore: bool}}
     */
    public function list(array $options = []): array
    {
        $params = array_filter([
            'conversation_id' => $options['conversation_id'] ?? null,
            'status' => $options['status'] ?? null,
            'limit' => isset($options['limit']) ? (string) $options['limit'] : null,
            'offset' => isset($options['offset']) ? (string) $options['offset'] : null,
        ], fn($v) => $v !== null);

        return $this->client->get('/drafts', $params);
    }

    /**
     * Get a draft by ID
     *
     * @param string $id Draft ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function get(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Draft ID is required');
        }

        return $this->client->get("/drafts/{$id}");
    }

    /**
     * Update a draft
     *
     * @param string $id Draft ID
     * @param array{text?: string, mediaUrls?: array<string>, metadata?: array<string, mixed>} $data Update data
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function update(string $id, array $data): array
    {
        if (empty($id)) {
            throw new ValidationException('Draft ID is required');
        }

        $body = array_filter([
            'text' => $data['text'] ?? null,
            'mediaUrls' => $data['mediaUrls'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->patch("/drafts/{$id}", $body);
    }

    /**
     * Approve a draft
     *
     * @param string $id Draft ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function approve(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Draft ID is required');
        }

        return $this->client->post("/drafts/{$id}/approve");
    }

    /**
     * Reject a draft
     *
     * @param string $id Draft ID
     * @param string|null $reason Optional rejection reason
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function reject(string $id, ?string $reason = null): array
    {
        if (empty($id)) {
            throw new ValidationException('Draft ID is required');
        }

        $body = [];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        return $this->client->post("/drafts/{$id}/reject", $body);
    }
}
