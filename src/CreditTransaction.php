<?php

declare(strict_types=1);

namespace Sendly;

use DateTimeImmutable;

class CreditTransaction
{
    /** A credit purchase, including an auto-recharge */
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_USAGE = 'usage';
    public const TYPE_REFUND = 'refund';
    public const TYPE_BONUS = 'bonus';
    /** Credits moved between workspaces */
    public const TYPE_TRANSFER = 'transfer';
    /** Credits Sendly added to the account by hand */
    public const TYPE_ADMIN_GRANT = 'admin_grant';
    /** Test credits Sendly added to the account */
    public const TYPE_ADMIN_SEED = 'admin_seed';
    /** @deprecated Never recorded: the ledger has no adjustment transactions. */
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

    public function isCredit(): bool
    {
        return $this->amount > 0;
    }

    public function isDebit(): bool
    {
        return $this->amount < 0;
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
