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

    protected ?string $apiErrorCode = null;

    /** @var array<string, mixed>|null */
    protected ?array $responseBody = null;

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

    /**
     * The machine-readable `error` code the API responded with (for example
     * `rcs_field_locked` or `insufficient_permissions`), or null when the
     * error did not come from an API response.
     */
    public function getApiErrorCode(): ?string
    {
        return $this->apiErrorCode;
    }

    /**
     * Attach the API's `error` code to this exception.
     *
     * @return static
     */
    public function withApiErrorCode(?string $code): static
    {
        $this->apiErrorCode = $code;

        return $this;
    }

    /**
     * The decoded JSON body of the API's error response, or null when the
     * error did not come from an API response or the response body is not
     * JSON (an HTML 502 page, for example). Some refusals carry more than
     * `error` and `message`: a 409 `agent_in_use` lists the numbers the agent
     * still answers under `numbers`, and a 422 `invalid_address` carries a
     * corrected address (or null) under `suggested`.
     *
     * @return array<string, mixed>|null
     */
    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }

    /**
     * Attach the API's decoded error response body to this exception.
     *
     * @param array<string, mixed>|null $body
     * @return static
     */
    public function withResponseBody(?array $body): static
    {
        $this->responseBody = $body;

        return $this;
    }
}
