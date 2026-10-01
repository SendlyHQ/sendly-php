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
     * @param int $statusCode HTTP status of the response (400 or 422)
     */
    public function __construct(string $message = 'Validation failed', ?array $details = null, int $statusCode = 400)
    {
        parent::__construct($message, $statusCode);
        $this->details = $details;
    }
}
