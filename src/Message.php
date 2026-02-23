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
    public readonly DateTimeImmutable $updatedAt;
    public readonly ?DateTimeImmutable $deliveredAt;
    public readonly ?string $errorCode;
    public readonly ?string $errorMessage;
    public readonly int $retryCount;
    /** @var array<string, mixed>|null Custom metadata attached to the message */
    public readonly ?array $metadata;

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
        $this->updatedAt = $this->parseDateTime($data['updated_at'] ?? $data['updatedAt'] ?? null) ?? new DateTimeImmutable();
        $this->deliveredAt = $this->parseDateTime($data['delivered_at'] ?? $data['deliveredAt'] ?? null);
        $this->errorCode = $data['error_code'] ?? $data['errorCode'] ?? null;
        $this->errorMessage = $data['error_message'] ?? $data['errorMessage'] ?? null;
        $this->retryCount = (int) ($data['retry_count'] ?? $data['retryCount'] ?? 0);
        $this->metadata = $data['metadata'] ?? null;
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
