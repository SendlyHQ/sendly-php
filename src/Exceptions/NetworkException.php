<?php

declare(strict_types=1);

namespace Sendly\Exceptions;

use Exception;

/**
 * Thrown when a network error occurs
 */
class NetworkException extends SendlyException
{
    protected ?string $errorCode = 'NETWORK_ERROR';

    public function __construct(string $message = 'Network error occurred', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
