<?php

declare(strict_types=1);

namespace Sendly\Exceptions;

/**
 * Thrown when the request contains invalid parameters
 */
class ValidationException extends SendlyException
{
    protected ?string $errorCode = 'VALIDATION_ERROR';

    /**
     * @param string $message Error message
     * @param array<string, mixed>|null $details Validation details
     */
    public function __construct(string $message = 'Validation failed', ?array $details = null)
    {
        parent::__construct($message, 400);
        $this->details = $details;
    }
}
