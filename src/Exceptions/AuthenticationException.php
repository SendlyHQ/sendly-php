<?php

declare(strict_types=1);

namespace Sendly\Exceptions;

/**
 * Thrown when the API key is invalid or missing
 */
class AuthenticationException extends SendlyException
{
    protected ?string $errorCode = 'AUTHENTICATION_ERROR';

    public function __construct(string $message = 'Invalid or missing API key')
    {
        parent::__construct($message, 401);
    }
}
