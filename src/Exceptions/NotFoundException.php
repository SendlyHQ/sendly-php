<?php

declare(strict_types=1);

namespace Sendly\Exceptions;

/**
 * Thrown when the requested resource is not found
 */
class NotFoundException extends SendlyException
{
    protected ?string $errorCode = 'NOT_FOUND';

    public function __construct(string $message = 'Resource not found')
    {
        parent::__construct($message, 404);
    }
}
