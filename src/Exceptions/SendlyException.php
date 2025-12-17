<?php

declare(strict_types=1);

namespace Sendly\Exceptions;

use Exception;

/**
 * Base exception for all Sendly errors
 */
class SendlyException extends Exception
{
    protected ?string $errorCode = null;

    /** @var array<string, mixed>|null */
    protected ?array $details = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the error code
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Get error details
     *
     * @return array<string, mixed>|null
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }
}
