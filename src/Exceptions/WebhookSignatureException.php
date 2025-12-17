<?php

declare(strict_types=1);

namespace Sendly\Exceptions;

use Exception;

/**
 * Exception thrown when webhook signature verification fails.
 */
class WebhookSignatureException extends Exception
{
    public function __construct(string $message = 'Invalid webhook signature')
    {
        parent::__construct($message);
    }
}
