<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

class WhatsAppSignup
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Start connecting a number to WhatsApp.
     *
     * Charges a one-time $19 setup fee (no monthly fee) and returns a
     * `connectUrl`. Completing the connection requires a human: hand the URL
     * to your user — they open it in a browser and log in with Facebook to
     * link their WhatsApp Business Account. Then poll {@see get()} until the
     * status is `active`.
     *
     * Calling again for a number with an in-flight signup returns the
     * existing signup (same `connectUrl`) without charging again. Requires a
     * live API key.
     *
     * @param string $phoneNumber The number to connect, in E.164 format. Must
     *   be an active number in your workspace (provisioned, purchased, or
     *   ported into Sendly).
     * @return array{id: string, connectUrl: string, status: string} The
     *   signup with its `connectUrl`. `status` is `initiated` (waiting for a
     *   human to complete the connect URL), `registering`, `active`,
     *   `failed`, or `expired`.
     * @throws ValidationException If the number is not E.164, not in your workspace, or not eligible.
     */
    public function create(string $phoneNumber): array
    {
        $this->validatePhone($phoneNumber);

        return $this->client->post('/whatsapp/signup', [
            'phoneNumber' => $phoneNumber,
        ]);
    }

    /**
     * Get the status of a WhatsApp signup.
     *
     * @param string $id Signup ID
     * @return array{id: string, status: string, phoneNumber: string, businessAccountId: ?string, failureReasons: ?array<int, string>, updatedAt: string}
     *   `businessAccountId` is null before the human completes the connect
     *   step; `failureReasons` is set only when status is `failed`.
     * @throws ValidationException If ID is empty.
     */
    public function get(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Signup ID is required');
        }

        return $this->client->get("/whatsapp/signup/{$id}");
    }

    /**
     * Validate phone number format
     *
     * @throws ValidationException
     */
    private function validatePhone(string $phone): void
    {
        if (!preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
            throw new ValidationException(
                'Invalid phone number format. Use E.164 format (e.g., +15551234567)'
            );
        }
    }
}

class WhatsAppSenders
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List your WhatsApp senders.
     *
     * Returns the numbers connected (or connecting) to WhatsApp on your
     * workspace, newest first. An empty list means no number is connected
     * yet — start one with {@see WhatsAppSignup::create()}.
     *
     * Sender `status` is `pending` (connection in progress; not sendable
     * yet), `active` (can send and receive on WhatsApp), or `suspended`
     * (sending is currently suspended). `displayName` is the name recipients
     * see — chosen during the connect flow and reviewed by Meta; null until
     * set. `qualityRating` (e.g. "GREEN") is null before the first rating.
     *
     * @return array{senders: array<array{phoneNumber: string, displayName: ?string, status: string, qualityRating: ?string, createdAt: string}>}
     */
    public function list(): array
    {
        return $this->client->get('/whatsapp/senders');
    }
}

class WhatsAppTemplates
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List your WhatsApp templates.
     *
     * Template `status` is `PENDING` (Meta review usually takes 24-48h),
     * `APPROVED` (usable in template sends), `REJECTED` (edit with
     * {@see update()} to resubmit), or `PAUSED`/`DISABLED`
     * (quality-suspended by Meta).
     *
     * @return array{templates: array<array{id: string, name: string, language: string, category: string, status: string, qualityRating: ?string, rejectionReason: ?string, createdAt: string, updatedAt: string}>}
     */
    public function list(): array
    {
        return $this->client->get('/whatsapp/templates');
    }

    /**
     * Create a template and submit it to Meta for review.
     *
     * Review usually takes 24-48h; the template is usable once its status is
     * `APPROVED`. Meta may reclassify the category during review — the
     * category on the record is authoritative and drives per-message
     * pricing. Note: Meta has paused marketing template delivery to US (+1)
     * numbers. Requires a live API key.
     *
     * @param array{
     *   sender: string,
     *   name: string,
     *   language: string,
     *   category: string,
     *   body: string,
     *   footer?: string,
     *   header?: string,
     *   buttons?: array<int, array{type: string, text: string, url?: string, example?: array<int, string>}>,
     *   examples?: array<string, string>
     * } $params Template definition. `sender` (a WhatsApp-connected sending
     *   number, E.164), `name` (lowercase letters, digits, and underscores,
     *   e.g. `order_shipped`), `language` (e.g. `en_US`), `category`
     *   (`AUTHENTICATION`, `UTILITY`, or `MARKETING`), and `body` are
     *   required. Use `{{1}}`, `{{2}}`, … for body variables; every
     *   placeholder needs an example value in `examples`, keyed by
     *   placeholder number (e.g. `['1' => 'Acme Inc']`). Button `type` is
     *   `url` (may contain a `{{1}}` placeholder; supply `example` values),
     *   `quick_reply`, or `otp` (required on AUTHENTICATION templates).
     * @return array{id: string, name: string, language: string, category: string, status: string, qualityRating: ?string, rejectionReason: ?string, createdAt: string, updatedAt: string, warnings?: array<int, string>}
     *   The created template (status `PENDING`), with any non-blocking
     *   submission warnings.
     * @throws ValidationException If a required field is missing or the sender is not E.164.
     */
    public function create(array $params): array
    {
        if (empty($params['sender'])) {
            throw new ValidationException('sender is required');
        }
        if (empty($params['name'])) {
            throw new ValidationException('name is required');
        }
        if (empty($params['language'])) {
            throw new ValidationException('language is required');
        }
        if (empty($params['category'])) {
            throw new ValidationException('category is required');
        }
        if (empty($params['body'])) {
            throw new ValidationException('body is required');
        }
        $this->validatePhone((string) $params['sender']);

        $body = array_filter([
            'sender' => $params['sender'],
            'name' => $params['name'],
            'language' => $params['language'],
            'category' => $params['category'],
            'body' => $params['body'],
            'footer' => $params['footer'] ?? null,
            'header' => $params['header'] ?? null,
            'buttons' => $params['buttons'] ?? null,
            'examples' => $params['examples'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->post('/whatsapp/templates', $body);
    }

    /**
     * Edit an APPROVED or REJECTED template and resubmit it for review.
     *
     * This is the recovery path for rejections: template names are locked
     * for ~30 days after deletion, so editing a rejected template (rather
     * than deleting and re-creating it) is the way to fix it. The updated
     * template goes back to `PENDING` review. Requires a live API key.
     *
     * @param string $id Template ID
     * @param array{
     *   body?: string,
     *   footer?: string,
     *   header?: string,
     *   buttons?: array<int, array{type: string, text: string, url?: string, example?: array<int, string>}>,
     *   examples?: array<string, string>
     * } $params The fields to change; omitted fields keep their current value.
     * @return array{id: string, name: string, language: string, category: string, status: string, qualityRating: ?string, rejectionReason: ?string, createdAt: string, updatedAt: string}
     *   The updated template (status `PENDING`).
     * @throws ValidationException If ID is empty.
     */
    public function update(string $id, array $params): array
    {
        if (empty($id)) {
            throw new ValidationException('Template ID is required');
        }

        $body = array_filter([
            'body' => $params['body'] ?? null,
            'footer' => $params['footer'] ?? null,
            'header' => $params['header'] ?? null,
            'buttons' => $params['buttons'] ?? null,
            'examples' => $params['examples'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->patch("/whatsapp/templates/{$id}", $body);
    }

    /**
     * Delete a template.
     *
     * Meta locks a deleted template's name for ~30 days — re-creating it
     * fails with `template_name_locked` until the lock lifts. To fix a
     * rejected template, prefer {@see update()}. Requires a live API key.
     *
     * @param string $id Template ID
     * @return array{id: string, deleted: bool} Deletion confirmation
     * @throws ValidationException If ID is empty.
     */
    public function delete(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Template ID is required');
        }

        return $this->client->delete("/whatsapp/templates/{$id}");
    }

    /**
     * Validate phone number format
     *
     * @throws ValidationException
     */
    private function validatePhone(string $phone): void
    {
        if (!preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
            throw new ValidationException(
                'Invalid phone number format. Use E.164 format (e.g., +15551234567)'
            );
        }
    }
}

/**
 * WhatsApp resource — connect senders, manage Meta-reviewed message
 * templates, and check 24-hour conversation windows.
 *
 * WhatsApp is a first-class Sendly channel: connect a number you own, create
 * message templates, and send via
 * `$client->messages()->send(['channel' => 'whatsapp', ...])`.
 *
 * Connecting a number is a one-time $19 setup (no monthly fee) and always
 * ends with a human step: {@see WhatsAppSignup::create()} returns a
 * `connectUrl` that a person must open in a browser and log in with Facebook
 * to link their WhatsApp Business Account. Hand the URL to your user — the
 * connection cannot be completed programmatically.
 *
 * Two ways to reach a recipient:
 *
 *   - Inside a 24-hour window (the recipient messaged you in the last 24h):
 *     free-form text and media are allowed. Check with {@see window()}.
 *   - Anytime: an approved template. Templates are reviewed by Meta
 *     (typically 24-48h) and categorized as authentication, utility, or
 *     marketing — pricing follows the category and destination country.
 *
 * WhatsApp writes (signup, template create/update/delete, sends) require a
 * live API key (`sk_live_v1_xxx`).
 *
 * @see https://sendly.live/docs/whatsapp
 */
class WhatsApp
{
    private Sendly $client;
    public WhatsAppSignup $signup;
    public WhatsAppSenders $senders;
    public WhatsAppTemplates $templates;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
        $this->signup = new WhatsAppSignup($client);
        $this->senders = new WhatsAppSenders($client);
        $this->templates = new WhatsAppTemplates($client);
    }

    /**
     * Check whether a 24-hour customer-service window is open between one of
     * your WhatsApp senders and a recipient.
     *
     * Free-form text and media only deliver while a window is open (it opens
     * when the recipient messages you and lasts 24h from their last inbound
     * message). Outside a window, send an approved template.
     *
     * @param string $from Your WhatsApp-connected sending number, in E.164 format
     * @param string $to The recipient's number, in E.164 format
     * @return array{open: bool, expiresAt: ?string} Whether the window is
     *   open and when it closes (ISO 8601), or null when no window is open.
     * @throws ValidationException If either number is not E.164.
     */
    public function window(string $from, string $to): array
    {
        $this->validatePhone($from);
        $this->validatePhone($to);

        return $this->client->get('/whatsapp/window', [
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Validate phone number format
     *
     * @throws ValidationException
     */
    private function validatePhone(string $phone): void
    {
        if (!preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
            throw new ValidationException(
                'Invalid phone number format. Use E.164 format (e.g., +15551234567)'
            );
        }
    }
}
