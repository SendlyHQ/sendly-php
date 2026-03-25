<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

/**
 * Conversations resource for managing two-way messaging threads
 */
class Conversations
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List conversations
     *
     * @param array{limit?: int, offset?: int, status?: string} $options Query options
     * @return array{data: array<array<string, mixed>>, pagination: array{total: int, limit: int, offset: int, hasMore: bool}}
     */
    public function list(array $options = []): array
    {
        $params = array_filter([
            'limit' => min($options['limit'] ?? 20, 100),
            'offset' => $options['offset'] ?? 0,
            'status' => $options['status'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->get('/conversations', $params);
    }

    /**
     * Get a conversation by ID
     *
     * @param string $id Conversation ID
     * @param array{includeMessages?: bool, messageLimit?: int, messageOffset?: int} $options Query options
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function get(string $id, array $options = []): array
    {
        if (empty($id)) {
            throw new ValidationException('Conversation ID is required');
        }

        $params = array_filter([
            'include_messages' => isset($options['includeMessages']) && $options['includeMessages'] ? 'true' : null,
            'message_limit' => $options['messageLimit'] ?? null,
            'message_offset' => $options['messageOffset'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->get("/conversations/{$id}", $params);
    }

    /**
     * Reply to a conversation
     *
     * @param string $id Conversation ID
     * @param string $text Message content
     * @param array{messageType?: string, metadata?: array<string, mixed>, mediaUrls?: array<string>} $options Additional options
     * @return array<string, mixed> The sent message
     * @throws ValidationException If parameters are invalid
     */
    public function reply(string $id, string $text, array $options = []): array
    {
        if (empty($id)) {
            throw new ValidationException('Conversation ID is required');
        }

        if (empty($text)) {
            throw new ValidationException('Message text is required');
        }

        $payload = ['text' => $text];

        if (isset($options['messageType'])) {
            $payload['messageType'] = $options['messageType'];
        }

        if (isset($options['metadata'])) {
            $payload['metadata'] = $options['metadata'];
        }

        if (isset($options['mediaUrls'])) {
            $payload['mediaUrls'] = $options['mediaUrls'];
        }

        return $this->client->post("/conversations/{$id}/messages", $payload);
    }

    /**
     * Update a conversation
     *
     * @param string $id Conversation ID
     * @param array{metadata?: array<string, mixed>, tags?: array<string>} $data Update data
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function update(string $id, array $data): array
    {
        if (empty($id)) {
            throw new ValidationException('Conversation ID is required');
        }

        $body = array_filter([
            'metadata' => $data['metadata'] ?? null,
            'tags' => $data['tags'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->patch("/conversations/{$id}", $body);
    }

    /**
     * Close a conversation
     *
     * @param string $id Conversation ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function close(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Conversation ID is required');
        }

        return $this->client->post("/conversations/{$id}/close");
    }

    /**
     * Reopen a conversation
     *
     * @param string $id Conversation ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function reopen(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Conversation ID is required');
        }

        return $this->client->post("/conversations/{$id}/reopen");
    }

    /**
     * Mark a conversation as read
     *
     * @param string $id Conversation ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function markRead(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Conversation ID is required');
        }

        return $this->client->post("/conversations/{$id}/mark-read");
    }

    /**
     * Add labels to a conversation
     *
     * @param string $id Conversation ID
     * @param array<string> $labelIds Label IDs to add
     * @return array<string, mixed>
     * @throws ValidationException If parameters are invalid
     */
    public function addLabels(string $id, array $labelIds): array
    {
        if (empty($id)) {
            throw new ValidationException('Conversation ID is required');
        }

        if (empty($labelIds)) {
            throw new ValidationException('Label IDs are required');
        }

        return $this->client->post("/conversations/{$id}/labels", [
            'labelIds' => $labelIds,
        ]);
    }

    /**
     * Remove a label from a conversation
     *
     * @param string $id Conversation ID
     * @param string $labelId Label ID to remove
     * @return array<string, mixed>
     * @throws ValidationException If parameters are invalid
     */
    public function removeLabel(string $id, string $labelId): array
    {
        if (empty($id)) {
            throw new ValidationException('Conversation ID is required');
        }

        if (empty($labelId)) {
            throw new ValidationException('Label ID is required');
        }

        return $this->client->delete("/conversations/{$id}/labels/{$labelId}");
    }

    /**
     * Get conversation context for AI/LLM consumption
     *
     * @param string $id Conversation ID
     * @param int|null $maxMessages Maximum number of messages to include
     * @return array{context: string, conversation: array<string, mixed>, tokenEstimate: int, business?: array<string, mixed>}
     * @throws ValidationException If ID is empty
     */
    public function getContext(string $id, ?int $maxMessages = null): array
    {
        if (empty($id)) {
            throw new ValidationException('Conversation ID is required');
        }

        $params = [];
        if ($maxMessages !== null) {
            $params['max_messages'] = $maxMessages;
        }

        return $this->client->get("/conversations/{$id}/context", $params);
    }
}
