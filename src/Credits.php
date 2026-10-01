<?php

declare(strict_types=1);

namespace Sendly;

class Credits
{
    public readonly int $balance;
    public readonly int $availableBalance;
    /** @deprecated The API does not report pending credits, so this is always 0. */
    public readonly int $pendingCredits;
    /** Credits held for messages still being sent; the same value as $reservedBalance */
    public readonly int $reservedCredits;
    /** Credits held for messages still being sent */
    public readonly int $reservedBalance;
    /** 'prepaid', or 'pooled' for a workspace that draws on an enterprise credit pool */
    public readonly ?string $billingMode;
    public readonly string $currency;

    /**
     * @param array<string, mixed> $data Response data
     */
    public function __construct(array $data)
    {
        $this->balance = (int) ($data['balance'] ?? 0);
        $this->availableBalance = (int) ($data['available_balance'] ?? $data['availableBalance'] ?? $data['balance'] ?? 0);
        $this->pendingCredits = (int) ($data['pending_credits'] ?? $data['pendingCredits'] ?? 0);
        $reserved = (int) ($data['reservedBalance'] ?? $data['reserved_balance'] ?? $data['reserved_credits'] ?? $data['reservedCredits'] ?? 0);
        $this->reservedCredits = $reserved;
        $this->reservedBalance = $reserved;
        $this->billingMode = $data['billingMode'] ?? $data['billing_mode'] ?? null;
        $this->currency = $data['currency'] ?? 'USD';
    }

    public function hasCredits(): bool
    {
        return $this->availableBalance > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'balance' => $this->balance,
            'available_balance' => $this->availableBalance,
            'pending_credits' => $this->pendingCredits,
            'reserved_credits' => $this->reservedCredits,
            'reserved_balance' => $this->reservedBalance,
            'billing_mode' => $this->billingMode,
            'currency' => $this->currency,
        ];
    }
}
