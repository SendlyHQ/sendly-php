<?php

declare(strict_types=1);

namespace Sendly;

use DateTimeImmutable;

class AccountVerification
{
    /** @deprecated The API does not report email verification, so this is always false. Read $status or isVerified(). */
    public readonly bool $emailVerified;
    /** @deprecated The API does not report phone verification, so this is always false. Read $status or isVerified(). */
    public readonly bool $phoneVerified;
    /** @deprecated The API does not report identity verification, so this is always false. Read $status or isVerified(). */
    public readonly bool $identityVerified;
    /**
     * The business verification's status: `verified` (or `approved`) once it
     * is approved, otherwise `pending`, `pending_review`, `submitted`,
     * `in_progress`, `processing`, `action_required` or `rejected`. Null when
     * the account has no business verification.
     */
    public readonly ?string $status;
    /** The kind of verification, such as `toll_free`, or null when there is none */
    public readonly ?string $type;
    public readonly ?string $region;
    public readonly ?DateTimeImmutable $submittedAt;
    public readonly ?DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->emailVerified = (bool) ($data['email_verified'] ?? $data['emailVerified'] ?? false);
        $this->phoneVerified = (bool) ($data['phone_verified'] ?? $data['phoneVerified'] ?? false);
        $this->identityVerified = (bool) ($data['identity_verified'] ?? $data['identityVerified'] ?? false);
        $this->status = $data['status'] ?? null;
        $this->type = $data['type'] ?? null;
        $this->region = $data['region'] ?? null;
        $this->submittedAt = $this->parseDateTime($data['submittedAt'] ?? $data['submitted_at'] ?? null);
        $this->updatedAt = $this->parseDateTime($data['updatedAt'] ?? $data['updated_at'] ?? null);
    }

    /**
     * Check if the business verification is approved
     */
    public function isVerified(): bool
    {
        return in_array($this->status, ['verified', 'approved'], true);
    }

    /**
     * Check if fully verified: the business verification is approved
     */
    public function isFullyVerified(): bool
    {
        return $this->isVerified() || ($this->emailVerified && $this->phoneVerified && $this->identityVerified);
    }

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
 * Represents account limits
 */
class AccountLimits
{
    public readonly int $messagesPerMinute;
    /** @deprecated The API does not report a per-second limit, so this is always 10. */
    public readonly int $messagesPerSecond;
    public readonly int $messagesPerDay;
    /** @deprecated The API does not report a batch size; this is the API's batch limit, 10,000 messages. */
    public readonly int $maxBatchSize;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->messagesPerMinute = (int) ($data['messagesPerMinute'] ?? $data['messages_per_minute'] ?? 60);
        $this->messagesPerSecond = (int) ($data['messages_per_second'] ?? $data['messagesPerSecond'] ?? 10);
        $this->messagesPerDay = (int) ($data['messages_per_day'] ?? $data['messagesPerDay'] ?? 10000);
        $this->maxBatchSize = (int) ($data['max_batch_size'] ?? $data['maxBatchSize'] ?? 10000);
    }
}

/**
 * Represents account information
 */
class Account
{
    public readonly string $id;
    public readonly string $email;
    /** @deprecated The API does not send a name, so this is always null. */
    public readonly ?string $name;
    /** @deprecated The API does not send a company name, so this is always null. The workspace name is $organization['name']. */
    public readonly ?string $companyName;
    public readonly AccountVerification $verification;
    public readonly AccountLimits $limits;
    public readonly DateTimeImmutable $createdAt;
    /** @var array<string, mixed>|null The workspace the API key belongs to (`id`, `name`, `isPersonal`), or null for a key outside a workspace */
    public readonly ?array $organization;
    /**
     * @var array<string, mixed>|null The workspace's own credit balance: `balance` and `reservedBalance`,
     *   as integers. A workspace on an enterprise credit pool spends from the pool instead, and
     *   account->credits() reports the pool's balance.
     */
    public readonly ?array $credits;
    /** @var array<string, mixed>|null The API key making the call (`id`, `name`, `type`, `scopes`, `createdAt`, `lastUsedAt`) */
    public readonly ?array $apiKey;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $user = is_array($data['user'] ?? null) ? $data['user'] : [];
        $this->id = $user['id'] ?? $data['id'] ?? '';
        $this->email = $user['email'] ?? $data['email'] ?? '';
        $this->name = $data['name'] ?? null;
        $this->companyName = $data['company_name'] ?? $data['companyName'] ?? null;
        $this->verification = new AccountVerification(is_array($data['verification'] ?? null) ? $data['verification'] : []);
        $this->limits = new AccountLimits(is_array($data['limits'] ?? null) ? $data['limits'] : []);
        $this->createdAt = $this->parseDateTime(
            $user['createdAt'] ?? $user['created_at'] ?? $data['created_at'] ?? $data['createdAt'] ?? null
        ) ?? new DateTimeImmutable();
        $this->organization = is_array($data['organization'] ?? null) ? $data['organization'] : null;
        $credits = is_array($data['credits'] ?? null) ? $data['credits'] : null;
        $this->credits = $credits === null ? null : array_merge($credits, [
            'balance' => (int) ($credits['balance'] ?? 0),
            'reservedBalance' => (int) ($credits['reservedBalance'] ?? 0),
        ]);
        $this->apiKey = is_array($data['apiKey'] ?? null) ? $data['apiKey'] : null;
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
