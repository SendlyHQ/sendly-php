<?php

declare(strict_types=1);

namespace Sendly;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Represents credit balance information
 */
class Credits
{
    public readonly int $balance;
    public readonly int $availableBalance;
    public readonly int $pendingCredits;
    public readonly int $reservedCredits;
    public readonly string $currency;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->balance = (int) ($data['balance'] ?? 0);
        $this->availableBalance = (int) ($data['available_balance'] ?? $data['availableBalance'] ?? $data['balance'] ?? 0);
        $this->pendingCredits = (int) ($data['pending_credits'] ?? $data['pendingCredits'] ?? 0);
        $this->reservedCredits = (int) ($data['reserved_credits'] ?? $data['reservedCredits'] ?? 0);
        $this->currency = $data['currency'] ?? 'USD';
    }

    /**
     * Check if there are credits available
     */
    public function hasCredits(): bool
    {
        return $this->availableBalance > 0;
    }

    /**
     * Convert to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'balance' => $this->balance,
            'available_balance' => $this->availableBalance,
            'pending_credits' => $this->pendingCredits,
            'reserved_credits' => $this->reservedCredits,
            'currency' => $this->currency,
        ];
    }
}

/**
 * Represents a credit transaction
 */
class CreditTransaction
{
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_USAGE = 'usage';
    public const TYPE_REFUND = 'refund';
    public const TYPE_BONUS = 'bonus';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public readonly string $id;
    public readonly string $type;
    public readonly int $amount;
    public readonly int $balanceAfter;
    public readonly ?string $description;
    public readonly ?string $referenceId;
    public readonly DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? '';
        $this->type = $data['type'] ?? '';
        $this->amount = (int) ($data['amount'] ?? 0);
        $this->balanceAfter = (int) ($data['balance_after'] ?? $data['balanceAfter'] ?? 0);
        $this->description = $data['description'] ?? null;
        $this->referenceId = $data['reference_id'] ?? $data['referenceId'] ?? null;
        $this->createdAt = $this->parseDateTime($data['created_at'] ?? $data['createdAt'] ?? null) ?? new DateTimeImmutable();
    }

    /**
     * Check if this is a credit (positive amount)
     */
    public function isCredit(): bool
    {
        return $this->amount > 0;
    }

    /**
     * Check if this is a debit (negative amount)
     */
    public function isDebit(): bool
    {
        return $this->amount < 0;
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

/**
 * Represents an API key
 */
class ApiKey
{
    public readonly string $id;
    public readonly string $name;
    public readonly string $prefix;
    public readonly ?string $lastUsedAt;
    public readonly DateTimeImmutable $createdAt;
    public readonly ?DateTimeImmutable $expiresAt;
    public readonly bool $isActive;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? '';
        $this->name = $data['name'] ?? '';
        $this->prefix = $data['prefix'] ?? '';
        $this->lastUsedAt = $data['last_used_at'] ?? $data['lastUsedAt'] ?? null;
        $this->createdAt = $this->parseDateTime($data['created_at'] ?? $data['createdAt'] ?? null) ?? new DateTimeImmutable();
        $this->expiresAt = $this->parseDateTime($data['expires_at'] ?? $data['expiresAt'] ?? null);
        $this->isActive = (bool) ($data['is_active'] ?? $data['isActive'] ?? true);
    }

    /**
     * Check if the API key is expired
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return $this->expiresAt < new DateTimeImmutable();
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

/**
 * Represents account verification status
 */
class AccountVerification
{
    public readonly bool $emailVerified;
    public readonly bool $phoneVerified;
    public readonly bool $identityVerified;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->emailVerified = (bool) ($data['email_verified'] ?? $data['emailVerified'] ?? false);
        $this->phoneVerified = (bool) ($data['phone_verified'] ?? $data['phoneVerified'] ?? false);
        $this->identityVerified = (bool) ($data['identity_verified'] ?? $data['identityVerified'] ?? false);
    }

    /**
     * Check if fully verified
     */
    public function isFullyVerified(): bool
    {
        return $this->emailVerified && $this->phoneVerified && $this->identityVerified;
    }
}

/**
 * Represents account limits
 */
class AccountLimits
{
    public readonly int $messagesPerSecond;
    public readonly int $messagesPerDay;
    public readonly int $maxBatchSize;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->messagesPerSecond = (int) ($data['messages_per_second'] ?? $data['messagesPerSecond'] ?? 10);
        $this->messagesPerDay = (int) ($data['messages_per_day'] ?? $data['messagesPerDay'] ?? 10000);
        $this->maxBatchSize = (int) ($data['max_batch_size'] ?? $data['maxBatchSize'] ?? 1000);
    }
}

/**
 * Represents account information
 */
class Account
{
    public readonly string $id;
    public readonly string $email;
    public readonly ?string $name;
    public readonly ?string $companyName;
    public readonly AccountVerification $verification;
    public readonly AccountLimits $limits;
    public readonly DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? '';
        $this->email = $data['email'] ?? '';
        $this->name = $data['name'] ?? null;
        $this->companyName = $data['company_name'] ?? $data['companyName'] ?? null;
        $this->verification = new AccountVerification($data['verification'] ?? []);
        $this->limits = new AccountLimits($data['limits'] ?? []);
        $this->createdAt = $this->parseDateTime($data['created_at'] ?? $data['createdAt'] ?? null) ?? new DateTimeImmutable();
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
