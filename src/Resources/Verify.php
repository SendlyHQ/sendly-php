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
     *   template_id?: string,
     *   profile_id?: string,
     *   app_name?: string,
     *   timeout_secs?: int,
     *   code_length?: int
     * } $options Additional options
     * @return array{
     *   id: string,
     *   status: string,
     *   phone: string,
     *   expires_at: string,
     *   sandbox: bool,
     *   sandbox_code?: string,
     *   message?: string
     * }
     */
    public function send(string $phone, array $options = []): array
    {
        $body = array_merge(['to' => $phone], $options);
        return $this->client->post('/verify', $body);
    }

    /**
     * Resend a verification code
     *
     * @param string $id Verification ID
     * @return array{
     *   id: string,
     *   status: string,
     *   phone: string,
     *   expires_at: string,
     *   sandbox: bool,
     *   sandbox_code?: string,
     *   message?: string
     * }
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
     * @return array{
     *   id: string,
     *   status: string,
     *   phone: string,
     *   verified_at?: string,
     *   remaining_attempts?: int
     * }
     */
    public function check(string $id, string $code): array
    {
        return $this->client->post("/verify/{$id}/check", ['code' => $code]);
    }

    /**
     * Get a verification by ID
     *
     * @param string $id Verification ID
     * @return array{
     *   id: string,
     *   status: string,
     *   phone: string,
     *   delivery_status: string,
     *   attempts: int,
     *   max_attempts: int,
     *   expires_at: string,
     *   verified_at: ?string,
     *   created_at: string,
     *   sandbox: bool,
     *   app_name?: string,
     *   template_id?: string,
     *   profile_id?: string
     * }
     */
    public function get(string $id): array
    {
        return $this->client->get("/verify/{$id}");
    }

    /**
     * List verifications
     *
     * @param array{limit?: int, status?: string} $options Query options
     * @return array{verifications: array<array<string, mixed>>, pagination: array{limit: int, has_more: bool}}
     */
    public function list(array $options = []): array
    {
        return $this->client->get('/verify', $options);
    }
}
