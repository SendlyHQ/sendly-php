<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;

class Sessions
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Create a hosted verification session
     *
     * @param array{
     *   success_url: string,
     *   cancel_url?: string,
     *   brand_name?: string,
     *   brand_color?: string,
     *   metadata?: array<string, mixed>
     * } $options Session options
     * @return array<string, mixed>
     */
    public function create(array $options): array
    {
        return $this->client->post('/verify/sessions', $options);
    }

    /**
     * Validate a session token after user completes verification
     *
     * @param string $token The one-time token from callback
     * @return array{valid: bool, session_id?: string, phone?: string, verified_at?: string, metadata?: array<string, mixed>}
     */
    public function validate(string $token): array
    {
        return $this->client->post('/verify/sessions/validate', ['token' => $token]);
    }
}

class Verify
{
    private Sendly $client;
    public Sessions $sessions;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
        $this->sessions = new Sessions($client);
    }

    /**
     * Send a verification code
     *
     * @param string $phone Phone number in E.164 format
     * @param array{
     *   channel?: string,
     *   codeLength?: int,
     *   expiresIn?: int,
     *   maxAttempts?: int,
     *   templateId?: string,
     *   profileId?: string,
     *   appName?: string,
     *   locale?: string,
     *   metadata?: array<string, mixed>
     * } $options Additional options
     * @return array{verification: array<string, mixed>, code?: string}
     */
    public function send(string $phone, array $options = []): array
    {
        $body = array_merge(['phone' => $phone], $options);
        return $this->client->post('/verify', $body);
    }

    /**
     * Resend a verification code
     *
     * @param string $id Verification ID
     * @return array{verification: array<string, mixed>, code?: string}
     */
    public function resend(string $id): array
    {
        return $this->client->post("/verify/{$id}/resend");
    }

    /**
     * Check a verification code
     *
     * @param string $id Verification ID
     * @param string $code The verification code to check
     * @return array{valid: bool, status: string, verification?: array<string, mixed>}
     */
    public function check(string $id, string $code): array
    {
        return $this->client->post("/verify/{$id}/check", ['code' => $code]);
    }

    /**
     * Get a verification by ID
     *
     * @param string $id Verification ID
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->client->get("/verify/{$id}");
    }

    /**
     * List verifications
     *
     * @param array{limit?: int, status?: string, phone?: string} $options Query options
     * @return array{verifications: array<array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function list(array $options = []): array
    {
        return $this->client->get('/verify', $options);
    }
}
