<?php

declare(strict_types=1);

namespace Sendly;

use Sendly\Exceptions\WebhookSignatureException;

/**
 * Webhook utilities for verifying and parsing Sendly webhook events.
 *
 * Example usage:
 * ```php
 * // In your webhook handler (e.g., Laravel)
 * public function handleWebhook(Request $request)
 * {
 *     $signature = $request->header('X-Sendly-Signature');
 *     $timestamp = $request->header('X-Sendly-Timestamp');
 *     $payload = $request->getContent();
 *
 *     try {
 *         $event = Webhooks::parseEvent($payload, $signature, config('sendly.webhook_secret'), $timestamp);
 *
 *         switch ($event->type) {
 *             case 'message.delivered':
 *                 // $event->data is the message view, present for message.* events
 *                 Log::info("Message delivered: " . $event->data->id);
 *                 break;
 *             case 'rcs_agent.live':
 *                 // Lifecycle events carry a different object entirely, so
 *                 // $event->data is null. Read $event->object instead.
 *                 Log::info("RCS agent live: " . $event->object['agent_id']);
 *                 break;
 *         }
 *
 *         return response('OK', 200);
 *     } catch (WebhookSignatureException $e) {
 *         return response('Invalid signature', 401);
 *     }
 * }
 * ```
 */
class Webhooks
{
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    /**
     * Verify webhook signature from Sendly.
     *
     * @param string $payload    Raw request body as string
     * @param string $signature  X-Sendly-Signature header value
     * @param string $secret     Your webhook secret from dashboard
     * @param string|null $timestamp X-Sendly-Timestamp header value (recommended)
     * @return bool True if signature is valid, false otherwise
     */
    public static function verifySignature(string $payload, string $signature, string $secret, ?string $timestamp = null): bool
    {
        if (empty($payload) || empty($signature) || empty($secret)) {
            return false;
        }

        if ($timestamp !== null) {
            $signedPayload = $timestamp . '.' . $payload;
            if (abs(time() - (int)$timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
                return false;
            }
        } else {
            $signedPayload = $payload;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Parse and validate a webhook event.
     *
     * @param string $payload    Raw request body as string
     * @param string $signature  X-Sendly-Signature header value
     * @param string $secret     Your webhook secret from dashboard
     * @param string|null $timestamp X-Sendly-Timestamp header value (recommended)
     * @return WebhookEvent Parsed and validated event
     * @throws WebhookSignatureException If signature is invalid or payload is malformed
     */
    public static function parseEvent(string $payload, string $signature, string $secret, ?string $timestamp = null): WebhookEvent
    {
        if (!self::verifySignature($payload, $signature, $secret, $timestamp)) {
            throw new WebhookSignatureException('Invalid webhook signature');
        }

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data) || !isset($data['id']) || !isset($data['type']) || !isset($data['data'])) {
            throw new WebhookSignatureException('Invalid event structure');
        }

        return new WebhookEvent($data);
    }

    /**
     * Generate a webhook signature for testing purposes.
     *
     * @param string $payload The payload to sign
     * @param string $secret  The secret to use for signing
     * @param string|null $timestamp Optional timestamp to include in signature
     * @return string The signature in the format "sha256=..."
     */
    public static function generateSignature(string $payload, string $secret, ?string $timestamp = null): string
    {
        $signedPayload = $timestamp !== null ? $timestamp . '.' . $payload : $payload;
        return 'sha256=' . hash_hmac('sha256', $signedPayload, $secret);
    }
}

/**
 * Webhook event from Sendly.
 */
class WebhookEvent
{
    public readonly string $id;
    public readonly string $type;

    /**
     * The message view of `data.object`.
     *
     * Populated for `message.*` events only. It is `null` for every other
     * event type, because their `data.object` is not a message: `rcs_*`,
     * `whatsapp_*`, `call.*`, `brand.*`, `campaign.*`, `assignment.*`,
     * `number.*`, `port*`, `contact*`, `conversation.*`, `draft.*`,
     * `verification.*`, and any event type this SDK release does not know
     * about yet. Read those through {@see WebhookEvent::$object} or
     * {@see WebhookEvent::objectAs()}.
     */
    public readonly ?WebhookMessageData $data;

    /**
     * `data.object` exactly as it arrived, for every event type.
     *
     * Keys and values are verbatim: nothing is renamed, nothing is defaulted,
     * and a JSON `null` stays `null`.
     *
     * @var array<string, mixed>
     */
    public readonly array $object;

    public readonly int|string $created;
    public readonly string $apiVersion;
    public readonly bool $livemode;

    /**
     * @param array<string, mixed>|object $event Decoded webhook envelope
     */
    public function __construct(array|object $event)
    {
        /** @var array<string, mixed> $envelope */
        $envelope = self::toArray($event);

        $this->id = $envelope['id'];
        $this->type = $envelope['type'];

        $node = $envelope['data'] ?? [];
        if (!is_array($node)) {
            $node = [];
        }
        $object = isset($node['object']) && is_array($node['object']) ? $node['object'] : $node;
        /** @var array<string, mixed> $object */
        $this->object = $object;

        $this->data = self::isMessageEvent($this->type) ? new WebhookMessageData($object) : null;

        $created = $envelope['created'] ?? $envelope['created_at'] ?? 0;
        $this->created = is_int($created) || is_string($created) ? $created : 0;
        $apiVersion = $envelope['api_version'] ?? '2024-01';
        $this->apiVersion = is_string($apiVersion) ? $apiVersion : '2024-01';
        $this->livemode = (bool) ($envelope['livemode'] ?? false);
    }

    /**
     * Whether an event type carries a message at `data.object`.
     *
     * Only `message.*` events do. Everything else carries a lifecycle object
     * of its own shape, which is why {@see WebhookEvent::$data} is null there.
     */
    public static function isMessageEvent(string $type): bool
    {
        // message.opt_in and message.opt_out share the message.* prefix but
        // carry an opt-out record ({phone_number, keyword, from_number,
        // timestamp}), not a message. Treating them as messages produced a
        // message view with every field null, which is the invented-value
        // problem this class exists to remove.
        if ($type === 'message.opt_in' || $type === 'message.opt_out') {
            return false;
        }

        return str_starts_with($type, 'message.');
    }

    /**
     * Read one key off `data.object`.
     *
     * Returns `$default` only when the key is absent. A key the payload sent
     * as `null` comes back as `null`, not as `$default`.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->object) ? $this->object[$key] : $default;
    }

    /**
     * Hydrate `data.object` into a class of your choosing.
     *
     * Use it for lifecycle events, whose payload is not message-shaped:
     *
     * ```php
     * $verification = $event->objectAs(WebhookVerificationData::class);
     * ```
     *
     * The class is built through its static `fromArray(array $data)` when it
     * has one, and otherwise through a constructor taking the array — the
     * convention every data class in this SDK follows.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function objectAs(string $class): object
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Class {$class} does not exist");
        }

        if (method_exists($class, 'fromArray')) {
            /** @var T $hydrated */
            $hydrated = $class::fromArray($this->object);
            return $hydrated;
        }

        /** @var T $hydrated */
        $hydrated = new $class($this->object);
        return $hydrated;
    }

    /**
     * The verification view of `data.object`, for `verification.*` events.
     * Null for every other event type.
     */
    public function verification(): ?WebhookVerificationData
    {
        if (!str_starts_with($this->type, 'verification.')) {
            return null;
        }

        return WebhookVerificationData::fromArray($this->object);
    }

    /** @deprecated Use $created instead */
    public function getCreatedAt(): int|string
    {
        return $this->created;
    }

    /**
     * Recursively convert object payloads into associative arrays so both
     * decode styles (`json_decode($body)` and `json_decode($body, true)`) work.
     */
    private static function toArray(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            return array_map([self::class, 'toArray'], $value);
        }

        return $value;
    }
}

/**
 * The message view of a `message.*` event's `data.object`.
 *
 * Every field is nullable and reflects the payload as it arrived. A field the
 * event did not carry is `null` — this class never substitutes a plausible
 * default for a value the server did not send.
 */
class WebhookMessageData
{
    public readonly ?string $id;
    public readonly ?string $status;
    public readonly ?string $to;
    public readonly ?string $from;
    public readonly ?string $direction;
    public readonly ?string $organizationId;
    public readonly ?string $text;
    public readonly ?string $error;
    public readonly ?string $errorCode;
    public readonly int|string|null $deliveredAt;
    public readonly int|string|null $failedAt;
    public readonly int|string|null $createdAt;
    public readonly ?int $segments;
    public readonly ?int $creditsUsed;
    public readonly ?string $messageFormat;
    /** @var array<int|string, mixed>|null */
    public readonly ?array $mediaUrls;
    public readonly ?int $retryCount;
    /** @var array<int|string, mixed>|null */
    public readonly ?array $metadata;
    public readonly ?string $batchId;

    /**
     * @param array<string, mixed>|object $data The event's `data.object`
     */
    public function __construct(array|object $data)
    {
        /** @var array<string, mixed> $d */
        $d = is_array($data) ? $data : get_object_vars($data);

        // `message_id` is the legacy flat-payload spelling of the message id.
        // Both name the message on a message.* event, so either is safe here.
        $this->id = self::asString($d, 'id', 'message_id', 'messageId');
        $this->status = self::asString($d, 'status');
        $this->to = self::asString($d, 'to');
        $this->from = self::asString($d, 'from');
        $this->direction = self::asString($d, 'direction');
        $this->organizationId = self::asString($d, 'organization_id', 'organizationId');
        $this->text = self::asString($d, 'text');
        $this->error = self::asString($d, 'error');
        $this->errorCode = self::asString($d, 'error_code', 'errorCode');
        $this->deliveredAt = self::asTimestamp($d, 'delivered_at', 'deliveredAt');
        $this->failedAt = self::asTimestamp($d, 'failed_at', 'failedAt');
        $this->createdAt = self::asTimestamp($d, 'created_at', 'createdAt');
        $this->segments = self::asInt($d, 'segments');
        $this->creditsUsed = self::asInt($d, 'credits_used', 'creditsUsed');
        $this->messageFormat = self::asString($d, 'message_format', 'messageFormat');
        $this->mediaUrls = self::asArray($d, 'media_urls', 'mediaUrls');
        $this->retryCount = self::asInt($d, 'retry_count', 'retryCount');
        $this->metadata = self::asArray($d, 'metadata');
        $this->batchId = self::asString($d, 'batch_id', 'batchId');
    }

    /** @deprecated Use $id instead */
    public function getMessageId(): ?string
    {
        return $this->id;
    }

    /**
     * First key present wins. Missing keys and keys sent as null both give null.
     *
     * @param array<string, mixed> $data
     */
    private static function pick(array $data, string ...$keys): mixed
    {
        // A key that is present but null does not satisfy the lookup: on a
        // message event {"id": null, "message_id": "..."} the id is genuinely
        // absent, and falling through to message_id is what HEAD's `??` chain
        // did. Using array_key_exists alone would short-circuit on the null and
        // lose the id.
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                return $data[$key];
            }
        }

        // Preserve an explicit null over a missing key, so "arrived as null"
        // stays distinguishable from "never sent".
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return null;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private static function asString(array $data, string ...$keys): ?string
    {
        $value = self::pick($data, ...$keys);

        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $data */
    private static function asInt(array $data, string ...$keys): ?int
    {
        $value = self::pick($data, ...$keys);

        if (is_int($value)) {
            return $value;
        }

        return is_float($value) || (is_string($value) && is_numeric($value)) ? (int) $value : null;
    }

    /** @param array<string, mixed> $data */
    private static function asTimestamp(array $data, string ...$keys): int|string|null
    {
        $value = self::pick($data, ...$keys);

        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int|string, mixed>|null
     */
    private static function asArray(array $data, string ...$keys): ?array
    {
        $value = self::pick($data, ...$keys);

        if (is_array($value)) {
            return $value;
        }

        return $value instanceof \stdClass ? get_object_vars($value) : null;
    }
}

/**
 * The verification view of a `verification.*` event's `data.object`.
 *
 * Reach it with {@see WebhookEvent::verification()} or
 * {@see WebhookEvent::objectAs()}. Like the message view, a field the event
 * did not carry is null rather than a stand-in value.
 */
class WebhookVerificationData
{
    /**
     * @param array<int|string, mixed>|null $metadata
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $organizationId = null,
        public readonly ?string $phone = null,
        public readonly ?string $status = null,
        public readonly ?string $deliveryStatus = null,
        public readonly ?int $attempts = null,
        public readonly ?int $maxAttempts = null,
        public readonly int|string|null $expiresAt = null,
        public readonly int|string|null $verifiedAt = null,
        public readonly int|string|null $createdAt = null,
        public readonly ?string $appName = null,
        public readonly ?string $templateId = null,
        public readonly ?string $profileId = null,
        public readonly ?array $metadata = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $string = static function (string ...$keys) use ($data): ?string {
            foreach ($keys as $key) {
                if (array_key_exists($key, $data)) {
                    return is_string($data[$key]) ? $data[$key] : null;
                }
            }
            return null;
        };

        $int = static function (string ...$keys) use ($data): ?int {
            foreach ($keys as $key) {
                if (array_key_exists($key, $data)) {
                    return is_int($data[$key]) ? $data[$key] : null;
                }
            }
            return null;
        };

        $timestamp = static function (string ...$keys) use ($data): int|string|null {
            foreach ($keys as $key) {
                if (array_key_exists($key, $data)) {
                    return is_int($data[$key]) || is_string($data[$key]) ? $data[$key] : null;
                }
            }
            return null;
        };

        $metadata = $data['metadata'] ?? null;

        return new self(
            id: $string('id'),
            organizationId: $string('organization_id', 'organizationId'),
            phone: $string('phone'),
            status: $string('status'),
            deliveryStatus: $string('delivery_status', 'deliveryStatus'),
            attempts: $int('attempts'),
            maxAttempts: $int('max_attempts', 'maxAttempts'),
            expiresAt: $timestamp('expires_at', 'expiresAt'),
            verifiedAt: $timestamp('verified_at', 'verifiedAt'),
            createdAt: $timestamp('created_at', 'createdAt'),
            appName: $string('app_name', 'appName'),
            templateId: $string('template_id', 'templateId'),
            profileId: $string('profile_id', 'profileId'),
            metadata: is_array($metadata) ? $metadata : null,
        );
    }
}
