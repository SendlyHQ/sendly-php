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
 *                 Log::info("Message delivered: " . $event->data->id);
 *                 break;
 *             case 'message.failed':
 *                 Log::error("Message failed: " . $event->data->error);
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

        $data = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);

        if (!isset($data->id) || !isset($data->type) || !isset($data->data)) {
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
    public readonly WebhookMessageData $data;
    public readonly int|string $created;
    public readonly string $apiVersion;
    public readonly bool $livemode;

    public function __construct(object $data)
    {
        $this->id = $data->id;
        $this->type = $data->type;
        $obj = isset($data->data->object) ? $data->data->object : $data->data;
        $this->data = new WebhookMessageData($obj);
        $this->created = $data->created ?? $data->created_at ?? 0;
        $this->apiVersion = $data->api_version ?? '2024-01';
        $this->livemode = $data->livemode ?? false;
    }

    /** @deprecated Use $created instead */
    public function getCreatedAt(): int|string
    {
        return $this->created;
    }
}

/**
 * Webhook message data.
 */
class WebhookMessageData
{
    public readonly string $id;
    public readonly string $status;
    public readonly string $to;
    public readonly string $from;
    public readonly string $direction;
    public readonly ?string $organizationId;
    public readonly ?string $text;
    public readonly ?string $error;
    public readonly ?string $errorCode;
    public readonly int|string|null $deliveredAt;
    public readonly int|string|null $failedAt;
    public readonly int|string|null $createdAt;
    public readonly int $segments;
    public readonly int $creditsUsed;
    public readonly ?string $messageFormat;
    public readonly ?array $mediaUrls;
    public readonly ?int $retryCount;
    public readonly ?array $metadata;
    public readonly ?string $batchId;

    public function __construct(object $data)
    {
        $this->id = $data->id ?? $data->message_id ?? '';
        $this->status = $data->status ?? '';
        $this->to = $data->to ?? '';
        $this->from = $data->from ?? '';
        $this->direction = $data->direction ?? 'outbound';
        $this->organizationId = $data->organization_id ?? null;
        $this->text = $data->text ?? null;
        $this->error = $data->error ?? null;
        $this->errorCode = $data->error_code ?? null;
        $this->deliveredAt = $data->delivered_at ?? null;
        $this->failedAt = $data->failed_at ?? null;
        $this->createdAt = $data->created_at ?? null;
        $this->segments = $data->segments ?? 1;
        $this->creditsUsed = $data->credits_used ?? 0;
        $this->messageFormat = $data->message_format ?? null;
        $this->mediaUrls = isset($data->media_urls) ? (array)$data->media_urls : null;
        $this->retryCount = $data->retry_count ?? null;
        $this->metadata = isset($data->metadata) ? (array)$data->metadata : null;
        $this->batchId = $data->batch_id ?? null;
    }

    /** @deprecated Use $id instead */
    public function getMessageId(): string
    {
        return $this->id;
    }
}

class WebhookVerificationData
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $organizationId,
        public readonly string $phone,
        public readonly string $status,
        public readonly string $deliveryStatus,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly int|string|null $expiresAt,
        public readonly int|string|null $verifiedAt,
        public readonly int|string|null $createdAt,
        public readonly ?string $appName,
        public readonly ?string $templateId,
        public readonly ?string $profileId,
        public readonly ?array $metadata,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            organizationId: $data['organization_id'] ?? null,
            phone: $data['phone'] ?? '',
            status: $data['status'] ?? '',
            deliveryStatus: $data['delivery_status'] ?? 'queued',
            attempts: $data['attempts'] ?? 0,
            maxAttempts: $data['max_attempts'] ?? 3,
            expiresAt: $data['expires_at'] ?? null,
            verifiedAt: $data['verified_at'] ?? null,
            createdAt: $data['created_at'] ?? null,
            appName: $data['app_name'] ?? null,
            templateId: $data['template_id'] ?? null,
            profileId: $data['profile_id'] ?? null,
            metadata: $data['metadata'] ?? null,
        );
    }
}
