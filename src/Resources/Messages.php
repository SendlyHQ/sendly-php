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
     * Send an SMS or MMS message.
     *
     * Two calling conventions are supported:
     *
     *   Positional (PHP-idiomatic):
     *     $client->messages->send('+1...', 'hello');
     *     $client->messages->send('+1...', 'hello', 'transactional');
     *
     *   Array of options (matches our Node/Python/Ruby SDKs and our
     *   published code examples):
     *     $client->messages->send([
     *         'to' => '+1...',
     *         'text' => 'hello',
     *         'messageType' => 'transactional',
     *         'metadata' => [...],
     *         'mediaUrls' => [...],
     *     ]);
     *
     * Pass 'channel' => 'whatsapp' in the options array to send on WhatsApp
     * instead of SMS. WhatsApp sends require a live API key and a `from`
     * number with an active WhatsApp connection (see $client->whatsapp).
     * Provide one of `text` (free-form, only deliverable inside an open
     * 24-hour window — outside it the API responds 422
     * `whatsapp_window_closed`), `mediaUrls` (a single media attachment;
     * optional `text` becomes its caption — also window-bound), or
     * `template` (an approved template; works regardless of the window,
     * with `name`, `language`, and optional `variables`/`buttons`):
     *     $client->messages->send([
     *         'channel' => 'whatsapp',
     *         'to' => '+1...',
     *         'from' => '+1...',
     *         'template' => [
     *             'name' => 'order_shipped',
     *             'language' => 'en_US',
     *             'variables' => ['1' => 'Acme Inc', '2' => '#4821'],
     *         ],
     *     ]);
     *
     * Pass 'channel' => 'rcs' in the options array to send on RCS instead.
     * RCS sends require a live API key and a sendable RCS agent on your
     * workspace (see $client->rcs). Provide exactly one of `text`
     * (free-form, optionally with `suggestions` — suggested replies and URL
     * actions) or `card` (a rich card with `title`, `description`, and
     * optional `mediaUrl`, `orientation`, and `suggestions`). `agentId`
     * picks the sending agent when your workspace has more than one.
     * Recipients that can't receive RCS get `text` delivered as plain SMS
     * (billed as SMS) unless `fallbackToSms` is false — the response
     * discloses which channel delivered (see {@see sendRcs()}):
     *     $client->messages->send([
     *         'channel' => 'rcs',
     *         'to' => '+1...',
     *         'text' => 'Your order has shipped!',
     *         'suggestions' => [
     *             ['reply' => ['text' => 'Notify me', 'postbackData' => 'notify']],
     *         ],
     *     ]);
     *
     * @param string|array<string, mixed> $to Recipient phone number in E.164 format, OR an options array as above
     * @param string|null $text Message content (max 1600 characters). Required when $to is a string.
     * @param string|null $messageType 'marketing' (default, subject to quiet hours) or 'transactional' (24/7)
     * @param array<string, mixed>|null $metadata Custom JSON metadata (max 4KB)
     * @param array<string>|null $mediaUrls Media URLs to attach (sends as MMS)
     * @param string|null $from Sender ID or phone number (optional)
     * @param string|null $idempotencyKey Idempotency key for this send (1-255 printable
     *   ASCII characters; also accepted as 'idempotencyKey' in the options array). The
     *   SDK already generates a key per logical request automatically, so the server can
     *   dedupe the SDK's own retries. Supply your own key when you need idempotency
     *   across process restarts or your own retry loops - repeating a request with the
     *   same key within 24 hours returns the original response instead of executing again.
     * @return Message|array<string, mixed> The sent message. SMS sends return
     *   a Message; WhatsApp sends return the raw message array (id, channel,
     *   message_format, to, from, text, status, segments, creditsUsed,
     *   whatsapp: [kind, template?, messageId], createdAt, metadata); RCS
     *   sends return the raw message array (see {@see sendRcs()}).
     * @throws ValidationException If parameters are invalid
     */
    public function send(string|array $to, ?string $text = null, ?string $messageType = null, ?array $metadata = null, ?array $mediaUrls = null, ?string $from = null, ?string $idempotencyKey = null): Message|array
    {
        // Array-style call: extract keys and delegate to positional form
        // so all validation + payload assembly stays in one place.
        if (is_array($to)) {
            $options = $to;
            if (($options['channel'] ?? null) === 'whatsapp') {
                return $this->sendWhatsApp($options);
            }
            if (($options['channel'] ?? null) === 'rcs') {
                return $this->sendRcs($options);
            }
            return $this->send(
                (string) ($options['to'] ?? ''),
                isset($options['text']) ? (string) $options['text'] : null,
                isset($options['messageType']) ? (string) $options['messageType'] : null,
                $options['metadata'] ?? null,
                $options['mediaUrls'] ?? null,
                isset($options['from']) ? (string) $options['from'] : null,
                isset($options['idempotencyKey']) ? (string) $options['idempotencyKey'] : null,
            );
        }

        if ($text === null) {
            throw new ValidationException('text is required');
        }

        $this->validatePhone($to);
        $this->validateText($text);
        $this->validateMessageType($messageType);

        $payload = [
            'to' => $to,
            'text' => $text,
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

        if ($mediaUrls !== null) {
            $payload['mediaUrls'] = $mediaUrls;
        }

        $response = $this->client->post('/messages', $payload, $idempotencyKey);

        $data = $response['message'] ?? $response['data'] ?? $response;
        return new Message($data);
    }

    /**
     * Send a WhatsApp message ('channel' => 'whatsapp').
     *
     * @param array<string, mixed> $options WhatsApp message options
     * @return array<string, mixed> The created WhatsApp message
     * @throws ValidationException If no content is provided or a number is invalid
     */
    private function sendWhatsApp(array $options): array
    {
        $to = (string) ($options['to'] ?? '');
        $from = (string) ($options['from'] ?? '');
        $this->validatePhone($to);
        $this->validatePhone($from);

        $text = isset($options['text']) ? (string) $options['text'] : null;
        $mediaUrls = $options['mediaUrls'] ?? $options['media_urls'] ?? null;
        $template = $options['template'] ?? null;

        $hasMedia = is_array($mediaUrls) && count($mediaUrls) > 0;
        if (($text === null || $text === '') && !$hasMedia && $template === null) {
            throw new ValidationException('Provide \'text\', \'mediaUrls\', or \'template\'');
        }

        $payload = [
            'channel' => 'whatsapp',
            'to' => $to,
            'from' => $from,
        ];

        if ($text !== null) {
            $payload['text'] = $text;
        }

        if ($hasMedia) {
            $payload['mediaUrls'] = array_values($mediaUrls);
        }

        if ($template !== null) {
            $payload['template'] = $template;
        }

        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $payload['metadata'] = $options['metadata'];
        }

        return $this->client->post(
            '/messages',
            $payload,
            isset($options['idempotencyKey']) ? (string) $options['idempotencyKey'] : null,
        );
    }

    /**
     * Send an RCS message ('channel' => 'rcs').
     *
     * @param array<string, mixed> $options RCS message options
     * @return array<string, mixed> The created message. Delivered as RCS the
     *   response has channel 'rcs' (id, channel, message_format, to, from,
     *   text, status, segments, creditsUsed, rcs: [kind, agentId, agentName],
     *   createdAt, metadata); when the recipient can't receive RCS and the
     *   fallback is on, it has channel 'sms' and fellBackTo 'sms' (billed as
     *   SMS), with rcs: [requestedChannel, agentId, suggestionsDropped?] —
     *   suggestions have no SMS form and are dropped.
     * @throws ValidationException If content is missing or ambiguous, or the number is invalid
     */
    private function sendRcs(array $options): array
    {
        $to = (string) ($options['to'] ?? '');
        $this->validatePhone($to);

        $text = isset($options['text']) ? (string) $options['text'] : null;
        $card = $options['card'] ?? null;

        $hasText = $text !== null && $text !== '';
        if ($hasText === ($card !== null)) {
            throw new ValidationException('Provide exactly one of \'text\' or \'card\'');
        }

        $payload = [
            'channel' => 'rcs',
            'to' => $to,
        ];

        if (isset($options['agentId'])) {
            $payload['agentId'] = (string) $options['agentId'];
        }

        if ($hasText) {
            $payload['text'] = $text;
        }

        if ($card !== null) {
            $payload['card'] = $card;
        }

        if (isset($options['suggestions']) && is_array($options['suggestions'])) {
            $payload['suggestions'] = array_values($options['suggestions']);
        }

        if (array_key_exists('fallbackToSms', $options)) {
            $payload['fallbackToSms'] = (bool) $options['fallbackToSms'];
        }

        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $payload['metadata'] = $options['metadata'];
        }

        return $this->client->post(
            '/messages',
            $payload,
            isset($options['idempotencyKey']) ? (string) $options['idempotencyKey'] : null,
        );
    }

    /**
     * Send a group MMS to 2-8 recipients (US/Canada only).
     *
     * Creates a multi-party MMS conversation: every recipient sees the others
     * and replies fan out to all participants. Group messaging is an A2P
     * 10DLC capability — the sending number must be an MMS-enabled,
     * 10DLC-registered number you own. Omit `from` to use your workspace's
     * default sender. Requires the `group_mms` (and, for media, `enable_mms`)
     * feature to be enabled for your account.
     *
     *   $client->messages->sendGroup([
     *       'to' => ['+14155551234', '+14155555678'],
     *       'text' => 'Hey team - quick sync at noon?',
     *   ]);
     *
     * @param array{to: array<string>, text?: string, from?: string, mediaUrls?: array<string>, messageType?: string, idempotencyKey?: string} $options
     *   Group message options. `to` (2-8 US/CA recipients) is required, plus
     *   at least one of `text` or `mediaUrls`. `mediaUrls` may also be given
     *   as `media_urls`. `idempotencyKey` (1-255 printable ASCII characters)
     *   overrides the auto-generated per-request key.
     * @return array<string, mixed> The group message (id, status, to, group_message_id, simulated)
     * @throws ValidationException If recipient count is out of range or no body is provided
     */
    public function sendGroup(array $options): array
    {
        $to = $options['to'] ?? [];
        if (!is_array($to) || count($to) < 2) {
            throw new ValidationException('Group messaging requires at least 2 recipients in \'to\'');
        }
        if (count($to) > 8) {
            throw new ValidationException('Group messaging supports at most 8 recipients');
        }

        foreach ($to as $recipient) {
            $this->validatePhone((string) $recipient);
        }

        $text = isset($options['text']) ? (string) $options['text'] : null;
        $mediaUrls = $options['mediaUrls'] ?? $options['media_urls'] ?? null;
        $from = isset($options['from']) ? (string) $options['from'] : null;
        $messageType = isset($options['messageType']) ? (string) $options['messageType'] : null;

        $hasMedia = is_array($mediaUrls) && count($mediaUrls) > 0;
        if (($text === null || $text === '') && !$hasMedia) {
            throw new ValidationException('Provide \'text\' or \'mediaUrls\'');
        }

        if ($text !== null) {
            $this->validateText($text);
        }
        $this->validateMessageType($messageType);

        $payload = ['to' => array_values($to)];
        if ($text !== null && $text !== '') {
            $payload['text'] = $text;
        }
        if ($from !== null) {
            $payload['from'] = $from;
        }
        if ($hasMedia) {
            $payload['mediaUrls'] = $mediaUrls;
        }
        if ($messageType !== null) {
            $payload['messageType'] = $messageType;
        }

        return $this->client->post(
            '/messages/group',
            $payload,
            isset($options['idempotencyKey']) ? (string) $options['idempotencyKey'] : null,
        );
    }

    /**
     * AI-enhance a draft message for clarity, compliance, and send-readiness.
     *
     * Rewrites the supplied text into a single, polished SMS segment (max 160
     * characters) and returns a short explanation of what changed. Pass
     * $messageType to steer the rewrite; with no $text it generates a suitable
     * message for that type instead. At least one of $text or $messageType is
     * required. Requires the `ai_classification` feature; when AI is
     * unavailable the original text is returned with an empty explanation.
     *
     * @param string|null $text Draft text to enhance
     * @param string|null $messageType 'marketing' or 'transactional' to steer the rewrite
     * @return array<string, mixed> The enhanced text, an explanation, and the model used
     * @throws ValidationException If neither $text nor $messageType is provided
     */
    public function enhance(?string $text = null, ?string $messageType = null): array
    {
        if (($text === null || $text === '') && ($messageType === null || $messageType === '')) {
            throw new ValidationException('Provide \'text\' or \'messageType\'');
        }

        $payload = [];
        if ($text !== null) {
            $payload['text'] = $text;
        }
        if ($messageType !== null && $messageType !== '') {
            $payload['messageType'] = $messageType;
        }

        return $this->client->post('/ai/enhance', $payload);
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
     * @param string|null $idempotencyKey Idempotency key (1-255 printable ASCII characters). Overrides the auto-generated per-request key.
     * @return array<string, mixed> The scheduled message
     * @throws ValidationException If parameters are invalid
     */
    public function schedule(string $to, string $text, string $scheduledAt, ?string $from = null, ?string $messageType = null, ?array $metadata = null, ?string $idempotencyKey = null): array
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

        return $this->client->post('/messages/schedule', $payload, $idempotencyKey);
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
     * @param string|null $idempotencyKey Idempotency key (1-255 printable ASCII characters). No key is auto-generated for batch sends.
     * @return array<string, mixed> Batch response with batch ID and status
     * @throws ValidationException If parameters are invalid
     */
    public function sendBatch(array $messages, ?string $from = null, ?string $messageType = null, ?array $metadata = null, ?string $idempotencyKey = null): array
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

        // The batch endpoint dedupes header-less retries server-side by hashing
        // the request content; an auto-generated key would bypass that net for
        // identical cross-process re-runs, so only caller-supplied keys are sent.
        return $this->client->post('/messages/batch', $payload, $idempotencyKey, false);
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
