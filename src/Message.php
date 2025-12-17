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
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    public readonly string $id;
    public readonly string $to;
    public readonly string $text;
    public readonly string $status;
    public readonly int $creditsUsed;
    public readonly DateTimeImmutable $createdAt;
    public readonly DateTimeImmutable $updatedAt;
    public readonly ?DateTimeImmutable $deliveredAt;
    public readonly ?string $errorCode;
    public readonly ?string $errorMessage;

    /**
     * Create a Message from API response data
     *
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? '';
        $this->to = $data['to'] ?? '';
        $this->text = $data['text'] ?? '';
        $this->status = $data['status'] ?? '';
        $this->creditsUsed = (int) ($data['credits_used'] ?? 0);
        $this->createdAt = $this->parseDateTime($data['created_at'] ?? null) ?? new DateTimeImmutable();
        $this->updatedAt = $this->parseDateTime($data['updated_at'] ?? null) ?? new DateTimeImmutable();
        $this->deliveredAt = $this->parseDateTime($data['delivered_at'] ?? null);
        $this->errorCode = $data['error_code'] ?? null;
        $this->errorMessage = $data['error_message'] ?? null;
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
     * Check if message is pending
     */
    public function isPending(): bool
    {
        return in_array($this->status, [
            self::STATUS_QUEUED,
            self::STATUS_SENDING,
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
            'text' => $this->text,
            'status' => $this->status,
            'credits_used' => $this->creditsUsed,
            'created_at' => $this->createdAt->format(DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(DateTimeInterface::ATOM),
            'delivered_at' => $this->deliveredAt?->format(DateTimeInterface::ATOM),
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
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
