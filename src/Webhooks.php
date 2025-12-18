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
 *     $payload = $request->getContent();
 *
 *     try {
 *         $event = Webhooks::parseEvent($payload, $signature, config('sendly.webhook_secret'));
 *
 *         switch ($event->type) {
 *             case 'message.delivered':
 *                 Log::info("Message delivered: " . $event->data->messageId);
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
    /**
     * Verify webhook signature from Sendly.
     *
     * @param string $payload   Raw request body as string
     * @param string $signature X-Sendly-Signature header value
     * @param string $secret    Your webhook secret from dashboard
     * @return bool True if signature is valid, false otherwise
     */
    public static function verifySignature(string $payload, string $signature, string $secret): bool
    {
        if (empty($payload) || empty($signature) || empty($secret)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        // Timing-safe comparison
        return hash_equals($expected, $signature);
    }

    /**
     * Parse and validate a webhook event.
     *
     * @param string $payload   Raw request body as string
     * @param string $signature X-Sendly-Signature header value
     * @param string $secret    Your webhook secret from dashboard
     * @return WebhookEvent Parsed and validated event
     * @throws WebhookSignatureException If signature is invalid or payload is malformed
     */
    public static function parseEvent(string $payload, string $signature, string $secret): WebhookEvent
    {
        if (!self::verifySignature($payload, $signature, $secret)) {
            throw new WebhookSignatureException('Invalid webhook signature');
        }

        $data = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);

        if (!isset($data->id) || !isset($data->type) || !isset($data->data) || !isset($data->created_at)) {
            throw new WebhookSignatureException('Invalid event structure');
        }

        return new WebhookEvent($data);
    }

    /**
     * Generate a webhook signature for testing purposes.
     *
     * @param string $payload The payload to sign
     * @param string $secret  The secret to use for signing
     * @return string The signature in the format "sha256=..."
     */
    public static function generateSignature(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
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
    public readonly string $createdAt;
    public readonly string $apiVersion;

    public function __construct(object $data)
    {
        $this->id = $data->id;
        $this->type = $data->type;
        $this->data = new WebhookMessageData($data->data);
        $this->createdAt = $data->created_at;
        $this->apiVersion = $data->api_version ?? '2024-01-01';
    }
}

/**
 * Webhook message data.
 */
class WebhookMessageData
{
    public readonly string $messageId;
    public readonly string $status;
    public readonly string $to;
    public readonly string $from;
    public readonly ?string $error;
    public readonly ?string $errorCode;
    public readonly ?string $deliveredAt;
    public readonly ?string $failedAt;
    public readonly int $segments;
    public readonly int $creditsUsed;

    public function __construct(object $data)
    {
        $this->messageId = $data->message_id;
        $this->status = $data->status;
        $this->to = $data->to;
        $this->from = $data->from ?? '';
        $this->error = $data->error ?? null;
        $this->errorCode = $data->error_code ?? null;
        $this->deliveredAt = $data->delivered_at ?? null;
        $this->failedAt = $data->failed_at ?? null;
        $this->segments = $data->segments ?? 1;
        $this->creditsUsed = $data->credits_used ?? 0;
    }
}
