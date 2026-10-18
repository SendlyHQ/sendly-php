<?php

declare(strict_types=1);

namespace Sendly;

/**
 * The SMS fallback of a WhatsApp message sent with 'fallbackToSms' => true.
 *
 * `status` is 'pending' until WhatsApp's outcome is known, 'sent' when the
 * SMS went, 'failed' when the SMS was refused or credits ran out,
 * 'unavailable' when the SMS could no longer be sent from this number, and
 * 'skipped' when the WhatsApp failure isn't one an SMS fixes, such as the
 * recipient opting out. `reason` is what made the message fall back
 * ('window_closed', 'whatsapp_refused' or 'whatsapp_failed'). Values the API
 * adds later come through unchanged.
 *
 * A WhatsApp send returns an array, so its fallback is
 * `$message['fallback']`; wrap it with `new WhatsAppSmsFallback($message['fallback'])`
 * for these accessors. A Message read back with `messages()->get()` or
 * `messages()->list()` carries it as {@see Message::$fallback}.
 */
class WhatsAppSmsFallback
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_SKIPPED = 'skipped';

    public const REASON_WINDOW_CLOSED = 'window_closed';
    public const REASON_WHATSAPP_REFUSED = 'whatsapp_refused';
    public const REASON_WHATSAPP_FAILED = 'whatsapp_failed';

    /** Always 'sms' */
    public readonly string $channel;
    /** 'pending', 'sent', 'failed', 'unavailable' or 'skipped' */
    public readonly string $status;
    /** What made the message fall back; null until WhatsApp's failure is known */
    public readonly ?string $reason;
    /** The SMS message's id, once it exists */
    public readonly ?string $messageId;
    /** Credits charged for the SMS */
    public readonly ?int $creditsUsed;
    /** Why the SMS didn't go, in plain English */
    public readonly ?string $error;
    /** @var array<int, string>|null Template parts with no SMS form, such as 'quick_replies' or 'header_media' */
    public readonly ?array $contentDropped;

    /**
     * Create a WhatsAppSmsFallback from the API's `fallback` object
     *
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->channel = (string) ($data['channel'] ?? 'sms');
        $this->status = (string) ($data['status'] ?? self::STATUS_PENDING);
        $this->reason = isset($data['reason']) ? (string) $data['reason'] : null;
        $messageId = $data['messageId'] ?? $data['message_id'] ?? null;
        $this->messageId = $messageId !== null ? (string) $messageId : null;
        $creditsUsed = $data['creditsUsed'] ?? $data['credits_used'] ?? null;
        $this->creditsUsed = $creditsUsed !== null ? (int) $creditsUsed : null;
        $this->error = isset($data['error']) && is_string($data['error']) ? $data['error'] : null;
        $contentDropped = $data['contentDropped'] ?? $data['content_dropped'] ?? null;
        $this->contentDropped = is_array($contentDropped) ? array_values($contentDropped) : null;
    }

    /**
     * Check if WhatsApp's outcome is still unknown
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the SMS went in place of the WhatsApp message
     */
    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    /**
     * Convert to array, leaving out fields the API didn't send
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'channel' => $this->channel,
            'status' => $this->status,
            'reason' => $this->reason,
            'message_id' => $this->messageId,
            'credits_used' => $this->creditsUsed,
            'error' => $this->error,
            'content_dropped' => $this->contentDropped,
        ], static fn ($value) => $value !== null);
    }
}
