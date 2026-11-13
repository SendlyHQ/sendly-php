<?php

declare(strict_types=1);

namespace Sendly\Resources;

/**
 * Why a transfer failed, as reported in `transfer.failureReason` on a call
 * (null unless `transfer.status` is `failed`). `UNKNOWN` when no definite
 * answer came back. More values may be added.
 */
final class CallTransferFailureReason
{
    public const BUSY = 'busy';
    public const NO_ANSWER = 'no_answer';
    public const DECLINED = 'declined';
    public const UNAVAILABLE = 'unavailable';
    public const UNKNOWN = 'unknown';
}
