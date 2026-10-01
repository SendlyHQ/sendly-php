<?php

declare(strict_types=1);

namespace Sendly;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Represents an SMS message
 */
class Message
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_RETRYING = 'retrying';
    public const STATUS_READ = 'read';
    public const STATUS_RECEIVED = 'received';

    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_INBOUND = 'inbound';

    public const SENDER_TYPE_USER = 'user';
    public const SENDER_TYPE_API = 'api';
    public const SENDER_TYPE_SYSTEM = 'system';
    public const SENDER_TYPE_CAMPAIGN = 'campaign';

    public readonly string $id;
    public readonly string $to;
    public readonly ?string $from;
    public readonly string $text;
    public readonly string $status;
    public readonly string $direction;
    public readonly int $segments;
    public readonly int $creditsUsed;
    public readonly bool $isSandbox;
    public readonly ?string $senderType;
    public readonly ?string $telnyxMessageId;
    public readonly ?string $warning;
    public readonly ?string $senderNote;
    public readonly DateTimeImmutable $createdAt;
    /**
     * @deprecated The API keeps no update time for a message. This is the update
     *   time when a response carries one, otherwise $deliveredAt, otherwise $createdAt.
     */
    public readonly DateTimeImmutable $updatedAt;
    public readonly ?DateTimeImmutable $deliveredAt;
    public readonly ?string $errorCode;
    /** Why the message failed, as the API's `error` reports it; null when it has not failed */
    public readonly ?string $errorMessage;
    public readonly int $retryCount;
    /** @var array<string, mixed>|null Custom metadata attached to the message */
    public readonly ?array $metadata;
    /** @var array<string, mixed>|null AI classification metadata for inbound messages */
    public readonly ?array $aiMetadata;
    /**
     * True when the send was simulated and never reached a handset: a test key,
     * a sandbox destination, or a live key the account is not yet set up to
     * send with. Only a send response reports it.
     */
    public readonly bool $simulated;
    /** Why a live send was simulated; null otherwise */
    public readonly ?string $simulatedReason;
    /** 'sms', 'mms', 'whatsapp' or 'rcs' */
    public readonly string $messageFormat;
    /** @var array<int, string>|null The media attached to the message (MMS media, WhatsApp media or an RCS card image); null when there is none */
    public readonly ?array $mediaUrls;
    /** The batch the message was sent in; null when it was not sent in a batch */
    public readonly ?string $batchId;

    /**
     * Create a Message from API response data
     *
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? '';
        $this->to = $data['to'] ?? '';
        $this->from = $data['from'] ?? null;
        $this->text = $data['text'] ?? '';
        $this->status = $data['status'] ?? '';
        $this->direction = $data['direction'] ?? self::DIRECTION_OUTBOUND;
        $this->segments = (int) ($data['segments'] ?? 1);
        $this->creditsUsed = (int) ($data['credits_used'] ?? $data['creditsUsed'] ?? 0);
        $this->isSandbox = (bool) ($data['is_sandbox'] ?? $data['isSandbox'] ?? false);
        $this->senderType = $data['sender_type'] ?? $data['senderType'] ?? null;
        $this->telnyxMessageId = $data['telnyx_message_id'] ?? $data['telnyxMessageId'] ?? null;
        $this->warning = $data['warning'] ?? null;
        $this->senderNote = $data['sender_note'] ?? $data['senderNote'] ?? null;
        $this->createdAt = $this->parseDateTime($data['created_at'] ?? $data['createdAt'] ?? null) ?? new DateTimeImmutable();
        $this->deliveredAt = $this->parseDateTime($data['delivered_at'] ?? $data['deliveredAt'] ?? null);
        $this->updatedAt = $this->parseDateTime($data['updated_at'] ?? $data['updatedAt'] ?? null) ?? $this->deliveredAt ?? $this->createdAt;
        $this->errorCode = $data['error_code'] ?? $data['errorCode'] ?? null;
        $this->errorMessage = $data['error_message'] ?? $data['errorMessage'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null);
        $this->retryCount = (int) ($data['retry_count'] ?? $data['retryCount'] ?? 0);
        $this->metadata = $data['metadata'] ?? null;
        $this->aiMetadata = $data['ai_metadata'] ?? $data['aiMetadata'] ?? null;
        $this->simulated = (bool) ($data['simulated'] ?? false);
        $this->simulatedReason = $data['simulatedReason'] ?? $data['simulated_reason'] ?? null;
        $this->messageFormat = $data['messageFormat'] ?? $data['message_format'] ?? 'sms';
        $mediaUrls = $data['mediaUrls'] ?? $data['media_urls'] ?? null;
        $this->mediaUrls = is_array($mediaUrls) ? array_values($mediaUrls) : null;
        $this->batchId = $data['batchId'] ?? $data['batch_id'] ?? null;
    }

    /**
     * Check if the send was simulated rather than delivered to a handset
     */
    public function isSimulated(): bool
    {
        return $this->simulated;
    }

    /**
     * Check if message was delivered
     */
    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Check if message failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if message bounced (carrier rejected)
     */
    public function isBounced(): bool
    {
        return $this->status === self::STATUS_BOUNCED;
    }

    /**
     * Check if message is pending
     */
    public function isPending(): bool
    {
        return in_array($this->status, [
            self::STATUS_QUEUED,
            self::STATUS_SENT,
        ], true);
    }

    /**
     * Convert to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'to' => $this->to,
            'from' => $this->from,
            'text' => $this->text,
            'status' => $this->status,
            'direction' => $this->direction,
            'segments' => $this->segments,
            'credits_used' => $this->creditsUsed,
            'is_sandbox' => $this->isSandbox,
            'sender_type' => $this->senderType,
            'telnyx_message_id' => $this->telnyxMessageId,
            'warning' => $this->warning,
            'sender_note' => $this->senderNote,
            'created_at' => $this->createdAt->format(DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(DateTimeInterface::ATOM),
            'delivered_at' => $this->deliveredAt?->format(DateTimeInterface::ATOM),
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'retry_count' => $this->retryCount,
            'metadata' => $this->metadata,
            'ai_metadata' => $this->aiMetadata,
            'simulated' => $this->simulated,
            'simulated_reason' => $this->simulatedReason,
            'message_format' => $this->messageFormat,
            'media_urls' => $this->mediaUrls,
            'batch_id' => $this->batchId,
        ];
    }

    /**
     * Parse a datetime string
     */
    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
