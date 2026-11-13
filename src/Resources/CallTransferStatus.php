<?php

declare(strict_types=1);

namespace Sendly\Resources;

/**
 * Where a transfer is, as reported in `transfer.status` on a call: `PENDING`
 * while the number rings, `COMPLETED` once the caller was handed over, and
 * `FAILED` when the transfer did not go through and the caller is still on
 * the call. More values may be added.
 */
final class CallTransferStatus
{
    public const PENDING = 'pending';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
}
