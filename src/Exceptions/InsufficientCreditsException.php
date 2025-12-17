<?php

declare(strict_types=1);

namespace Sendly\Exceptions;

/**
 * Thrown when the account has insufficient credits
 */
class InsufficientCreditsException extends SendlyException
{
    protected ?string $errorCode = 'INSUFFICIENT_CREDITS';

    public function __construct(string $message = 'Insufficient credits')
    {
        parent::__construct($message, 402);
    }
}
