<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Generator;
use Sendly\Sendly;
use Sendly\Message;
use Sendly\MessageList;
use Sendly\Exceptions\ValidationException;

/**
 * Messages resource for sending and managing SMS
 */
class Messages
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Send an SMS or MMS message
     *
     * @param string $to Recipient phone number in E.164 format
     * @param string $text Message content (max 1600 characters)
     * @param string|null $messageType Message type: 'marketing' (default, subject to quiet hours) or 'transactional' (24/7)
     * @param array<string, mixed>|null $metadata Custom JSON metadata to attach to the message (max 4KB)
     * @param array<string>|null $mediaUrls Array of media URLs to attach (sends as MMS)
     * @return Message The sent message
     * @throws ValidationException If parameters are invalid
     */
    public function send(string $to, string $text, ?string $messageType = null, ?array $metadata = null, ?array $mediaUrls = null): Message
    {
        $this->validatePhone($to);
        $this->validateText($text);
        $this->validateMessageType($messageType);

        $payload = [
            'to' => $to,
            'text' => $text,
        ];

        if ($messageType !== null) {
            $payload['messageType'] = $messageType;
        }

        if ($metadata !== null) {
            $payload['metadata'] = $metadata;
        }

        if ($mediaUrls !== null) {
            $payload['mediaUrls'] = $mediaUrls;
        }

        $response = $this->client->post('/messages', $payload);

        $data = $response['message'] ?? $response['data'] ?? $response;
        return new Message($data);
    }

    /**
     * List messages
     *
     * @param array{limit?: int, offset?: int, status?: string, to?: string} $options Query options
     * @return MessageList Paginated list of messages
     */
    public function list(array $options = []): MessageList
    {
        $params = array_filter([
            'limit' => min($options['limit'] ?? 20, 100),
            'offset' => $options['offset'] ?? 0,
            'status' => $options['status'] ?? null,
            'to' => $options['to'] ?? null,
        ], fn($v) => $v !== null);

        $response = $this->client->get('/messages', $params);
        return new MessageList($response);
    }

    /**
     * Get a message by ID
     *
     * @param string $id Message ID
     * @return Message The message
     * @throws ValidationException If ID is empty
     */
    public function get(string $id): Message
    {
        if (empty($id)) {
            throw new ValidationException('Message ID is required');
        }

        $response = $this->client->get("/messages/{$id}");
        $data = $response['data'] ?? $response['message'] ?? $response;
        return new Message($data);
    }

    /**
     * Iterate over all messages with automatic pagination
     *
     * @param array{status?: string, to?: string, batchSize?: int} $options Query options
     * @return Generator<int, Message>
     */
    public function each(array $options = []): Generator
    {
        $batchSize = $options['batchSize'] ?? 100;
        $offset = 0;

        do {
            $page = $this->list([
                'limit' => $batchSize,
                'offset' => $offset,
                'status' => $options['status'] ?? null,
                'to' => $options['to'] ?? null,
            ]);

            foreach ($page as $message) {
                yield $message;
            }

            $offset += $batchSize;
        } while ($page->hasMore);
    }

    /**
     * Schedule an SMS message for future delivery
     *
     * @param string $to Recipient phone number in E.164 format
     * @param string $text Message content (max 1600 characters)
     * @param string $scheduledAt ISO 8601 datetime for when to send
     * @param string|null $from Sender ID or phone number (optional)
     * @param string|null $messageType Message type: 'marketing' (default, subject to quiet hours) or 'transactional' (24/7)
     * @param array<string, mixed>|null $metadata Custom JSON metadata to attach to the message (max 4KB)
     * @return array<string, mixed> The scheduled message
     * @throws ValidationException If parameters are invalid
     */
    public function schedule(string $to, string $text, string $scheduledAt, ?string $from = null, ?string $messageType = null, ?array $metadata = null): array
    {
        $this->validatePhone($to);
        $this->validateText($text);
        $this->validateMessageType($messageType);

        if (empty($scheduledAt)) {
            throw new ValidationException('Scheduled time is required');
        }

        $payload = [
            'to' => $to,
            'text' => $text,
            'scheduledAt' => $scheduledAt,
        ];

        if ($from !== null) {
            $payload['from'] = $from;
        }

        if ($messageType !== null) {
            $payload['messageType'] = $messageType;
        }

        if ($metadata !== null) {
            $payload['metadata'] = $metadata;
        }

        return $this->client->post('/messages/schedule', $payload);
    }

    /**
     * List scheduled messages
     *
     * @param array{limit?: int, offset?: int, status?: string} $options Query options
     * @return array<string, mixed> Paginated list of scheduled messages
     */
    public function listScheduled(array $options = []): array
    {
        $params = array_filter([
            'limit' => min($options['limit'] ?? 20, 100),
            'offset' => $options['offset'] ?? 0,
            'status' => $options['status'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->get('/messages/scheduled', $params);
    }

    /**
     * Get a scheduled message by ID
     *
     * @param string $id Scheduled message ID
     * @return array<string, mixed> The scheduled message
     * @throws ValidationException If ID is empty
     */
    public function getScheduled(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Scheduled message ID is required');
        }

        return $this->client->get("/messages/scheduled/{$id}");
    }

    /**
     * Cancel a scheduled message
     *
     * @param string $id Scheduled message ID
     * @return array<string, mixed> Cancellation result with refund details
     * @throws ValidationException If ID is empty
     */
    public function cancelScheduled(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Scheduled message ID is required');
        }

        return $this->client->delete("/messages/scheduled/{$id}");
    }

    /**
     * Send multiple SMS messages in a batch
     *
     * @param array<array{to: string, text: string}> $messages Array of messages
     * @param string|null $from Sender ID or phone number (optional, applies to all)
     * @param string|null $messageType Message type: 'marketing' (default, subject to quiet hours) or 'transactional' (24/7)
     * @param array<string, mixed>|null $metadata Custom JSON metadata to attach to all messages (max 4KB)
     * @return array<string, mixed> Batch response with batch ID and status
     * @throws ValidationException If parameters are invalid
     */
    public function sendBatch(array $messages, ?string $from = null, ?string $messageType = null, ?array $metadata = null): array
    {
        if (empty($messages)) {
            throw new ValidationException('Messages array cannot be empty');
        }

        $this->validateMessageType($messageType);

        foreach ($messages as $index => $message) {
            if (!isset($message['to']) || !isset($message['text'])) {
                throw new ValidationException("Message at index {$index} must have 'to' and 'text' fields");
            }
            $this->validatePhone($message['to']);
            $this->validateText($message['text']);
        }

        $payload = ['messages' => $messages];
        if ($from !== null) {
            $payload['from'] = $from;
        }

        if ($messageType !== null) {
            $payload['messageType'] = $messageType;
        }

        if ($metadata !== null) {
            $payload['metadata'] = $metadata;
        }

        return $this->client->post('/messages/batch', $payload);
    }

    /**
     * Get batch status by ID
     *
     * @param string $batchId Batch ID
     * @return array<string, mixed> Batch status and details
     * @throws ValidationException If batch ID is empty
     */
    public function getBatch(string $batchId): array
    {
        if (empty($batchId)) {
            throw new ValidationException('Batch ID is required');
        }

        return $this->client->get("/messages/batch/{$batchId}");
    }

    /**
     * List batches
     *
     * @param array{limit?: int, offset?: int, status?: string} $options Query options
     * @return array<string, mixed> Paginated list of batches
     */
    public function listBatches(array $options = []): array
    {
        $params = array_filter([
            'limit' => min($options['limit'] ?? 20, 100),
            'offset' => $options['offset'] ?? 0,
            'status' => $options['status'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->get('/messages/batches', $params);
    }

    /**
     * Preview a batch without sending (dry run)
     *
     * @param array<array{to: string, text: string}> $messages Array of messages
     * @param string|null $from Sender ID or phone number (optional, applies to all)
     * @param string|null $messageType Message type: 'marketing' (default) or 'transactional'
     * @return array<string, mixed> Preview showing what would happen if batch was sent
     * @throws ValidationException If parameters are invalid
     */
    public function previewBatch(array $messages, ?string $from = null, ?string $messageType = null): array
    {
        if (empty($messages)) {
            throw new ValidationException('Messages array cannot be empty');
        }

        $this->validateMessageType($messageType);

        foreach ($messages as $index => $message) {
            if (!isset($message['to']) || !isset($message['text'])) {
                throw new ValidationException("Message at index {$index} must have 'to' and 'text' fields");
            }
            $this->validatePhone($message['to']);
            $this->validateText($message['text']);
        }

        $payload = ['messages' => $messages];
        if ($from !== null) {
            $payload['from'] = $from;
        }

        if ($messageType !== null) {
            $payload['messageType'] = $messageType;
        }

        return $this->client->post('/messages/batch/preview', $payload);
    }

    /**
     * Validate phone number format
     *
     * @throws ValidationException
     */
    private function validatePhone(string $phone): void
    {
        if (!preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
            throw new ValidationException(
                'Invalid phone number format. Use E.164 format (e.g., +15551234567)'
            );
        }
    }

    /**
     * Validate message text
     *
     * @throws ValidationException
     */
    private function validateText(string $text): void
    {
        if (empty($text)) {
            throw new ValidationException('Message text is required');
        }

        if (strlen($text) > 1600) {
            throw new ValidationException(
                'Message text exceeds maximum length (1600 characters)'
            );
        }
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
