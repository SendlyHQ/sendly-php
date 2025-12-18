<?php

declare(strict_types=1);

namespace Sendly\Exceptions;

/**
 * Thrown when the rate limit is exceeded
 */
class RateLimitException extends SendlyException
{
    protected ?string $errorCode = 'RATE_LIMIT_EXCEEDED';
    private int $retryAfter;

    public function __construct(string $message = 'Rate limit exceeded', int $retryAfter = 0)
    {
        parent::__construct($message, 429);
        $this->retryAfter = $retryAfter;
    }

    /**
     * Get the number of seconds to wait before retrying
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
