<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

/**
 * @phpstan-type WhatsAppSignupSession array{id: string, status: string, phoneNumber: string, businessAccountId: ?string, failureReasons: ?array<int, string>, verificationMethod?: string, verificationAttemptsRemaining?: int, verificationCode?: ?string, updatedAt: string}
 */
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
     * live API key with the `whatsapp:write` scope (a test key gets 403
     * `whatsapp_requires_live_key`) and, in a team workspace, an owner or
     * admin (`settings:write`). After the Facebook step the signup stays
     * `registering` while WhatsApp activates the number. Activation usually
     * takes a few minutes but can take hours. If it hasn't finished about 6
     * hours after the session began, the session fails with
     * `registration_timeout` and the fee is refunded. If the connection
     * fails, the $19 fee is refunded automatically; once the number has
     * connected there is no refund, and a later disconnect gets nothing
     * back.
     *
     * While WhatsApp connections are unavailable the API answers a Facebook
     * connection with 503 `whatsapp_unavailable` before charging anything,
     * with `retryAfter: 3600` in the body and a `Retry-After: 3600` header.
     * Only signup returns it;
     * no send does. The client retries it like any 5xx before
     * throwing a SendlyException. After 5 failed, charged signups in 24 hours
     * it answers 429 `whatsapp_signup_limit_reached`, thrown as a
     * RateLimitException; try again the next day.
     *
     * To add another number to a WhatsApp Business Account this workspace
     * has already connected, pass its `businessAccountId` (as reported on
     * {@see WhatsAppSenders::list()} and on a signup). There is no Facebook
     * step and no `connectUrl`: the same eligibility checks, $19 fee, refund
     * and limit apply, WhatsApp sends the number a 6-digit code by text
     * (`verificationMethod` `sms`, the default) or voice call (`voice`), and
     * the signup comes back `verifying`. Submit the code with
     * {@see verify()}; {@see get()} also returns it as `verificationCode`
     * once the text has reached the number. Calling again for a number that
     * is already verifying returns the same signup without charging again
     * or sending a second code; a verifying signup more than 3 hours old
     * fails with `verification_expired` (its fee is refunded) and a new one,
     * with its own fee, starts instead. `displayName` (at most 512 characters)
     * defaults to the display name of the account's connected number, then
     * its business name; with neither the API answers 400
     * `display_name_required`. Other refusals: 404
     * `whatsapp_business_account_not_found` (no connected account with that
     * id, with at least one active number, in this workspace), 409
     * `whatsapp_signup_in_progress` (a Facebook connection for the number is
     * under way; the body's `id` is that signup), 409
     * `whatsapp_already_enabled`, and `whatsapp_verification_start_failed`
     * when WhatsApp won't send the code: a 422 when it refused (final), a
     * 502 when it couldn't be reached. Either way the signup fails and the
     * fee is refunded. With `businessAccountId` the client never retries a
     * 5xx or a failed connection: it throws the 502 as a SendlyException
     * (a failed connection as a NetworkException) at once, since each retry
     * could start a new signup with its own charge and refund that counts
     * toward the daily limit. Check with {@see get()} or call again when you
     * are ready. Without `businessAccountId`, a number that is verifying
     * answers 409
     * `whatsapp_verification_in_progress`, with the signup's `id` in the
     * body.
     *
     * @param string $phoneNumber The number to connect, in E.164 format. Must
     *   be an active number in your workspace (provisioned, purchased, or
     *   fully ported into Sendly).
     * @param array{businessAccountId?: string, verificationMethod?: string, displayName?: string} $options
     *   Only to add a number to an already-connected account:
     *   `businessAccountId` (required for that), `verificationMethod`
     *   (`sms` or `voice`) and `displayName`. Omitted keys are not sent.
     *   A null `businessAccountId` starts a Facebook connection, which
     *   ignores `verificationMethod` and `displayName`; a blank one throws a
     *   ValidationException.
     * @return ($options is array{businessAccountId: non-empty-string} ? WhatsAppSignupSession : array{id: string, connectUrl: string, status: string})
     *   Without `businessAccountId`, the signup with its `connectUrl`.
     *   `status` is `initiated` (waiting for a human to complete the connect
     *   URL), `registering`, `active`, or `failed`. The API does not send
     *   `expired`. With `businessAccountId`, the signup as {@see get()}
     *   returns it, `status` `verifying`, without `verificationCode`.
     * @throws ValidationException If the number is not E.164, not in your workspace, or not eligible.
     */
    public function create(string $phoneNumber, array $options = []): array
    {
        $this->validatePhone($phoneNumber);

        if (array_key_exists('businessAccountId', $options)
            && $options['businessAccountId'] !== null
            && trim((string) $options['businessAccountId']) === ''
        ) {
            throw new ValidationException('businessAccountId must be a non-empty string');
        }

        $body = array_merge(['phoneNumber' => $phoneNumber], array_filter([
            'businessAccountId' => $options['businessAccountId'] ?? null,
            'verificationMethod' => $options['verificationMethod'] ?? null,
            'displayName' => $options['displayName'] ?? null,
        ], fn($v) => $v !== null));

        return ($body['businessAccountId'] ?? '') === ''
            ? $this->client->post('/whatsapp/signup', $body)
            : $this->client->postWithoutRetry('/whatsapp/signup', $body);
    }

    /**
     * Get the status of a WhatsApp signup. Needs the `whatsapp:read` scope;
     * test keys work.
     *
     * @param string $id Signup ID
     * @return WhatsAppSignupSession
     *   `status` is `initiated`, `registering` (WhatsApp is activating the
     *   number; activation usually takes a few minutes but can take hours),
     *   `verifying` (a number added to a connected account is waiting for
     *   its code), `active`, or `failed`; the API does not send `expired`.
     *   More statuses may be added. `businessAccountId` is null before the
     *   human completes the connect step, and set while `verifying` and
     *   once `active`. While `verifying` the signup also carries
     *   `verificationMethod` (`sms` or `voice`),
     *   `verificationAttemptsRemaining` and `verificationCode`: the code once
     *   WhatsApp's text has reached the number, else null. Until a code has
     *   been submitted, it is the newest code that has arrived since the
     *   signup started, so after a resend it still shows the earlier code
     *   until the new one arrives. Once WhatsApp has checked a code, only a
     *   code that arrived after the last submission or resend is returned. A
     *   submission answered with 502 `whatsapp_verification_unavailable` is
     *   not counted, so the same unchecked code can come back, and
     *   submitting it again with {@see verify()} is safe.
     *   `failureReasons` is set only when status is `failed`, with one code:
     *   `setup_fee_payment_failed`,
     *   `signup_abandoned`, `meta_exchange_failed`, `registration_failed`,
     *   `waba_already_connected`, `waba_mismatch` (the WhatsApp Business
     *   Account chosen in the Facebook step doesn't hold the verified
     *   number), `registration_timeout` (activation hadn't finished about 6
     *   hours after the session began), `phone_number_mismatch`,
     *   `verification_start_failed` (WhatsApp wouldn't send the code),
     *   `verification_failed` (too many wrong codes) or
     *   `verification_expired` (no code was submitted or requested for an
     *   hour or more, or the signup was more than 3 hours old when the
     *   number was added again). If the connection fails, the $19 fee is
     *   refunded automatically.
     * @throws ValidationException If ID is empty.
     */
    public function get(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Signup ID is required');
        }

        return $this->client->get("/whatsapp/signup/" . rawurlencode($id));
    }

    /**
     * Submit the code WhatsApp sent to a number being added to a connected
     * account (a signup in `verifying`).
     *
     * A correct code connects the number: the signup comes back `active`
     * and the `whatsapp_account.connected` webhook fires. Submitting to a
     * signup that is already `active` returns it unchanged. Requires a live
     * API key with the `whatsapp:write` scope and, in a team workspace, an
     * owner or admin (`settings:write`).
     *
     * Refusals: 400 `invalid_verification_code` (not 6 digits), 422
     * `whatsapp_verification_code_invalid` (wrong code;
     * `getResponseBody()['attemptsRemaining']` says how many tries are
     * left), 409 `whatsapp_verification_failed` (the fifth wrong code: the
     * signup fails, the fee is refunded and `whatsapp_account.failed`
     * fires), 409 `whatsapp_verification_busy` (another code for the number
     * is being checked; try again in a moment), 409 `signup_not_active`
     * (the signup isn't waiting for a code, or is more than 3 hours old) and
     * 404 `signup_not_found`. A 502 `whatsapp_verification_unavailable`
     * (WhatsApp couldn't be reached; the attempt isn't counted, so submit
     * the code again shortly) or `whatsapp_activation_pending` (the code was
     * accepted but connecting the number didn't finish; Sendly is alerted,
     * so check with {@see get()} rather than submitting again) is thrown as
     * a SendlyException. The client never retries this call after a 5xx or
     * a failed connection, because every submission uses up one of the five
     * attempts.
     *
     * @param string $id Signup ID
     * @param string $code The 6-digit code; spaces and dashes are ignored
     * @return WhatsAppSignupSession The signup, `status` `active`.
     * @throws ValidationException If ID is empty.
     */
    public function verify(string $id, string $code): array
    {
        if (empty($id)) {
            throw new ValidationException('Signup ID is required');
        }

        /** @var WhatsAppSignupSession $signup */
        $signup = $this->client->postWithoutRetry("/whatsapp/signup/" . rawurlencode($id) . "/verify", [
            'code' => $code,
        ]);

        return $signup;
    }

    /**
     * Ask WhatsApp to send a new code to a number being added to a connected
     * account.
     *
     * Allowed 30 seconds after the signup last changed, a code submission
     * included; sooner answers 429 `whatsapp_verification_resend_too_soon`,
     * thrown as a RateLimitException whose `getRetryAfter()` is the seconds
     * to wait. When WhatsApp won't send one the API answers
     * `whatsapp_verification_resend_failed`: a 422 when it refused (wait a
     * few minutes), a 502 when it couldn't be reached. A signup that isn't
     * waiting for a code answers 409 `signup_not_active`; one already
     * `active` is returned unchanged. Requires a live API key with the
     * `whatsapp:write` scope and, in a team workspace, an owner or admin
     * (`settings:write`).
     *
     * @param string $id Signup ID
     * @param string|null $verificationMethod `sms` or `voice`. When null the
     *   API sends the new code by text (`sms`), even if the last one came by
     *   voice call.
     * @return WhatsAppSignupSession The signup, still `verifying`.
     * @throws ValidationException If ID is empty.
     */
    public function resend(string $id, ?string $verificationMethod = null): array
    {
        if (empty($id)) {
            throw new ValidationException('Signup ID is required');
        }

        $body = $verificationMethod !== null ? ['verificationMethod' => $verificationMethod] : [];

        /** @var WhatsAppSignupSession $signup */
        $signup = $this->client->post("/whatsapp/signup/" . rawurlencode($id) . "/resend", $body);

        return $signup;
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
 * @phpstan-type WhatsAppProfile array{phoneNumber: string, displayName: ?string, profilePhotoUrl: ?string, category: ?string, about: ?string, description: ?string, email: ?string, website: ?string, address: ?string}
 * @phpstan-type WhatsAppConversationalComponents array{phoneNumber: string, iceBreakers: array<int, string>, commands: array<int, array{command: string, description: string}>}
 * @phpstan-type WhatsAppCalling array{phoneNumber: string, callingEnabled: bool, outboundCallingAllowed: bool}
 */
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
     * yet — start one with {@see WhatsAppSignup::create()}. Needs the
     * `whatsapp:read` scope; test keys work.
     *
     * Sender `status` is `pending` (connection in progress; not sendable
     * yet), `active` (can send and receive on WhatsApp), or `suspended`
     * (sending is currently suspended). `displayName` is the name recipients
     * see — chosen during the connect flow and reviewed by Meta; null until
     * set. `qualityRating` (e.g. "GREEN") is null before the first rating.
     * `businessAccountId` is the WhatsApp Business Account the number
     * belongs to (pass it to {@see WhatsAppSignup::create()} to add another
     * number to that account) and `businessName` its business name; both
     * are null while the sender is `pending`, and `businessName` is also
     * null when the account has no business name on record.
     * `callingEnabled` says whether WhatsApp calling is on (see
     * {@see setCalling()}), and `outboundCallingAllowed` is false for every
     * +1 number (the US, Canada and the rest of the North American
     * Numbering Plan), and for Egyptian (+20), Vietnamese (+84) and Nigerian
     * (+234) numbers, which WhatsApp doesn't let businesses place calls
     * from.
     *
     * @return array{senders: array<array{phoneNumber: string, displayName: ?string, status: string, qualityRating: ?string, businessAccountId: ?string, businessName: ?string, callingEnabled: bool, outboundCallingAllowed: bool, createdAt: string}>}
     */
    public function list(): array
    {
        return $this->client->get('/whatsapp/senders');
    }

    /**
     * Get a sender's WhatsApp business profile.
     *
     * The business profile is what recipients see when they open the
     * sender's details in WhatsApp: display name, photo, category, about
     * line, description, and contact details. The sender must be `active`
     * (connected to WhatsApp). Needs the `whatsapp:read` scope; test keys
     * work.
     *
     * @param string $phoneNumber Your WhatsApp-connected sending number, in E.164 format
     * @return array{phoneNumber: string, displayName: ?string, profilePhotoUrl: ?string, category: ?string, about: ?string, description: ?string, email: ?string, website: ?string, address: ?string}
     *   The profile. Unset fields are null.
     * @throws ValidationException If the number is not E.164.
     */
    public function getProfile(string $phoneNumber): array
    {
        $this->validatePhone($phoneNumber);

        return $this->client->get("/whatsapp/senders/" . rawurlencode($phoneNumber) . "/profile");
    }

    /**
     * Update a sender's WhatsApp business profile.
     *
     * Provide only the fields to change; omitted fields keep their current
     * value. `about` is limited to 139 characters and `description` to 512.
     * `displayName` changes are reviewed by Meta before they take effect.
     * The profile photo is set with {@see uploadProfilePhoto()}. Requires a
     * live API key with the `whatsapp:write` scope and, in a team workspace,
     * an owner or admin (`settings:write`).
     *
     * @param string $phoneNumber Your WhatsApp-connected sending number, in E.164 format
     * @param array{displayName?: string, about?: string, description?: string, category?: string, email?: string, website?: string, address?: string} $fields
     *   The profile fields to change.
     * @return array{phoneNumber: string, displayName: ?string, profilePhotoUrl: ?string, category: ?string, about: ?string, description: ?string, email: ?string, website: ?string, address: ?string}
     *   The updated profile.
     * @throws ValidationException If the number is not E.164 or no field is provided.
     */
    public function updateProfile(string $phoneNumber, array $fields): array
    {
        $this->validatePhone($phoneNumber);

        $body = array_filter([
            'displayName' => $fields['displayName'] ?? null,
            'about' => $fields['about'] ?? null,
            'description' => $fields['description'] ?? null,
            'category' => $fields['category'] ?? null,
            'email' => $fields['email'] ?? null,
            'website' => $fields['website'] ?? null,
            'address' => $fields['address'] ?? null,
        ], fn($v) => $v !== null);

        if (empty($body)) {
            throw new ValidationException('Provide at least one profile field to update');
        }

        return $this->client->patch("/whatsapp/senders/" . rawurlencode($phoneNumber) . "/profile", $body);
    }

    /**
     * Set a sender's WhatsApp profile photo.
     *
     * The file is sent as the multipart field `file`. It must be a JPEG or
     * PNG (checked by its bytes) of at most 5 MB; WhatsApp wants it square
     * and at least 192 pixels wide (640 recommended). Requires a live API
     * key with the `whatsapp:write` scope and, in a team workspace, an owner
     * or admin (`settings:write`).
     *
     * Refusals: 400 `file_required` (no file), 400
     * `whatsapp_profile_photo_invalid` (not a JPEG or PNG), 413
     * `whatsapp_profile_photo_too_large` (over 5 MB, thrown as a
     * SendlyException), 404 `whatsapp_sender_not_connected`. A 502
     * `whatsapp_profile_update_failed` means WhatsApp didn't take the photo
     * (another image may succeed). The client never retries this call after
     * a 5xx or a failed connection: it throws a SendlyException (or a
     * NetworkException) at once, and you decide whether to upload again.
     *
     * @param string $phoneNumber Your WhatsApp-connected sending number, in E.164 format
     * @param string $filePath Path to the JPEG or PNG file
     * @param string|null $contentType MIME type of the file part (`image/jpeg`
     *   or `image/png`); auto-detected from the file name when null. The API
     *   checks the file's bytes, not this type.
     * @return WhatsAppProfile The profile with its new `profilePhotoUrl`.
     * @throws ValidationException If the number is not E.164, or the file is missing or unreadable.
     */
    public function uploadProfilePhoto(string $phoneNumber, string $filePath, ?string $contentType = null): array
    {
        $this->validatePhone($phoneNumber);

        if (!file_exists($filePath)) {
            throw new ValidationException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new ValidationException("File is not readable: {$filePath}");
        }

        $multipart = [
            [
                'name' => 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => basename($filePath),
            ],
        ];

        if ($contentType !== null) {
            $multipart[0]['headers'] = ['Content-Type' => $contentType];
        }

        /** @var WhatsAppProfile $profile */
        $profile = $this->client->postMultipartWithoutRetry(
            "/whatsapp/senders/" . rawurlencode($phoneNumber) . "/profile/photo",
            $multipart
        );

        return $profile;
    }

    /**
     * Remove a sender's WhatsApp profile photo.
     *
     * Requires a live API key with the `whatsapp:write` scope and, in a team
     * workspace, an owner or admin (`settings:write`). A number that isn't
     * connected throws NotFoundException (404 `whatsapp_sender_not_connected`).
     * A 502 `whatsapp_profile_update_failed` means WhatsApp didn't remove it;
     * it is retried like any 5xx, then thrown as a SendlyException.
     *
     * @param string $phoneNumber Your WhatsApp-connected sending number, in E.164 format
     * @return WhatsAppProfile The profile, `profilePhotoUrl` null.
     * @throws ValidationException If the number is not E.164.
     */
    public function deleteProfilePhoto(string $phoneNumber): array
    {
        $this->validatePhone($phoneNumber);

        /** @var WhatsAppProfile $profile */
        $profile = $this->client->delete("/whatsapp/senders/" . rawurlencode($phoneNumber) . "/profile/photo");

        return $profile;
    }

    /**
     * Get a sender's conversational components: the ice breakers and
     * commands WhatsApp shows in a chat with the business.
     *
     * Ice breakers are tappable suggestions shown when someone opens a chat
     * with the business for the first time; commands are shown when the
     * customer types "/". Needs the `whatsapp:read` scope; test keys work.
     * A number that isn't connected throws NotFoundException (404
     * `whatsapp_sender_not_connected`). A 502
     * `whatsapp_conversational_components_fetch_failed` means WhatsApp
     * couldn't be reached.
     *
     * @param string $phoneNumber Your WhatsApp-connected sending number, in E.164 format
     * @return WhatsAppConversationalComponents
     * @throws ValidationException If the number is not E.164.
     */
    public function getConversationalComponents(string $phoneNumber): array
    {
        $this->validatePhone($phoneNumber);

        /** @var WhatsAppConversationalComponents $components */
        $components = $this->client->get(
            "/whatsapp/senders/" . rawurlencode($phoneNumber) . "/conversational_components"
        );

        return $components;
    }

    /**
     * Replace a sender's ice breakers, commands, or both.
     *
     * Each list you pass replaces the stored one, an empty list clears it,
     * and a list you leave out is kept. Ice breakers: at most 4, each 1 to
     * 80 characters after trimming, no two the same (ignoring case).
     * Commands: at most 30, each a `command` of 1 to 32 letters, digits or
     * underscores (a leading "/" is dropped) and a `description` of 1 to 256
     * characters, no command twice. The API refuses anything else with 400
     * `invalid_request` and a message saying what to fix. Requires a live
     * API key with the `whatsapp:write` scope and, in a team workspace, an
     * owner or admin (`settings:write`). A number that isn't connected
     * throws NotFoundException (404 `whatsapp_sender_not_connected`). A 502
     * `whatsapp_conversational_components_update_failed` means WhatsApp
     * didn't save them.
     *
     * @param string $phoneNumber Your WhatsApp-connected sending number, in E.164 format
     * @param array{iceBreakers?: array<int, string>, commands?: array<int, array{command: string, description: string}>} $components
     * @return WhatsAppConversationalComponents The stored components, as WhatsApp saved them (trimmed, commands
     *   without the "/").
     * @throws ValidationException If the number is not E.164 or neither list is provided.
     */
    public function updateConversationalComponents(string $phoneNumber, array $components): array
    {
        $this->validatePhone($phoneNumber);

        $body = array_filter([
            'iceBreakers' => $components['iceBreakers'] ?? null,
            'commands' => $components['commands'] ?? null,
        ], fn($v) => $v !== null);

        if (empty($body)) {
            throw new ValidationException('Provide iceBreakers, commands, or both');
        }

        /** @var WhatsAppConversationalComponents $stored */
        $stored = $this->client->patch(
            "/whatsapp/senders/" . rawurlencode($phoneNumber) . "/conversational_components",
            $body
        );

        return $stored;
    }

    /**
     * Switch WhatsApp calling on or off for a sender.
     *
     * Once calling is on, a WhatsApp user calling the number rings exactly
     * like a phone call, by the number's voice mode (the dashboard or an AI
     * agent), and is billed at the normal inbound call rate. Turning it on
     * needs voice on for the number first (`ring_dashboard` or `agent`, set
     * with {@see VoiceNumbers::update()}), otherwise 409
     * `voice_not_enabled`. WhatsApp only allows calling once the account may
     * message at least 2,000 people a day and the number's display name is
     * approved; until then it answers 422 `whatsapp_calling_unavailable`. A
     * 502 `whatsapp_calling_update_failed` means WhatsApp couldn't be
     * reached. A number that isn't connected throws NotFoundException (404
     * `whatsapp_sender_not_connected`). The API has no endpoint for placing
     * WhatsApp calls. Requires
     * a live API key with the `whatsapp:write` scope and, in a team
     * workspace, an owner or admin (`settings:write`).
     *
     * @param string $phoneNumber Your WhatsApp-connected sending number, in E.164 format
     * @param bool $enabled Whether calling should be on
     * @return WhatsAppCalling
     * @throws ValidationException If the number is not E.164.
     */
    public function setCalling(string $phoneNumber, bool $enabled): array
    {
        $this->validatePhone($phoneNumber);

        /** @var WhatsAppCalling $calling */
        $calling = $this->client->patch("/whatsapp/senders/" . rawurlencode($phoneNumber) . "/calling", [
            'enabled' => $enabled,
        ]);

        return $calling;
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

class WhatsAppTemplates
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List your WhatsApp templates. Needs the `whatsapp:read` scope; test
     * keys work.
     *
     * Template `status` is `PENDING` (Meta review usually takes 24-48h),
     * `APPROVED` (usable in template sends), `REJECTED` (edit with
     * {@see update()} to resubmit), or `PAUSED`/`DISABLED`
     * (quality-suspended by Meta). Meta may report other statuses; they come
     * through in uppercase.
     *
     * @return array{templates: array<array{id: string, name: string, language: string, category: string, status: string, body: ?string, header: ?string, footer: ?string, examples: array<string, string>, qualityRating: ?string, rejectionReason: ?string, createdAt: string, updatedAt: string}>}
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
     * numbers. Requires a live API key with the `whatsapp:write` scope and,
     * in a team workspace, an owner, admin or member (`templates:write`). A
     * marketing template without an opt-out button is still accepted, with a
     * warning.
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
     *   (`AUTHENTICATION`, `UTILITY`, or `MARKETING`; uppercased by the
     *   server, with no default, and an update can't change it), and `body`
     *   are required. Use `{{1}}`, `{{2}}`, … for body variables; every
     *   placeholder needs an example value in `examples`, keyed by
     *   placeholder number (e.g. `['1' => 'Acme Inc']`). Button `type` is
     *   `url` (may contain a `{{1}}` placeholder; supply `example` values),
     *   `quick_reply`, or `otp` (required on AUTHENTICATION templates).
     *   `header` is fixed text: a header containing `{{n}}` is refused with
     *   `template_header_variable_unsupported`, because sends fill only body
     *   and button variables.
     * @return array{id: string, name: string, language: string, category: string, status: string, body: ?string, header: ?string, footer: ?string, examples: array<string, string>, qualityRating: ?string, rejectionReason: ?string, createdAt: string, updatedAt: string, warnings?: array<int, string>}
     *   The created template (status `PENDING`), with any non-blocking
     *   submission warnings.
     * @throws ValidationException If a required field is missing or the sender is not E.164,
     *   or the API refuses the template with a 400 `template_*` code and a
     *   readable message: `template_category_invalid` (category missing or
     *   not one of the three), `template_authentication_otp_button_required`,
     *   `template_authentication_no_links` (a link in the body or a URL
     *   button on an authentication template) or
     *   `template_header_variable_unsupported`.
     * @throws \Sendly\Exceptions\NotFoundException `whatsapp_sender_not_connected` (404) if the
     *   sender isn't connected to WhatsApp; this is checked first.
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
     * template goes back to `PENDING` review. The category can't be changed.
     * Requires a live API key with the `whatsapp:write` scope and, in a team
     * workspace, an owner, admin or member (`templates:write`).
     *
     * @param string $id Template ID
     * @param array{
     *   body?: string,
     *   footer?: string,
     *   header?: string,
     *   buttons?: array<int, array{type: string, text: string, url?: string, example?: array<int, string>}>,
     *   examples?: array<string, string>
     * } $params The fields to change; omitted fields keep their current value.
     *   `header` can't contain `{{n}}` variables
     *   (`template_header_variable_unsupported`).
     * @return array{id: string, name: string, language: string, category: string, status: string, body: ?string, header: ?string, footer: ?string, examples: array<string, string>, qualityRating: ?string, rejectionReason: ?string, createdAt: string, updatedAt: string}
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

        return $this->client->patch("/whatsapp/templates/" . rawurlencode($id), $body);
    }

    /**
     * Delete a template.
     *
     * Meta locks a deleted template's name for ~30 days — re-creating it
     * fails with `template_name_locked` until the lock lifts. To fix a
     * rejected template, prefer {@see update()}. Requires a live API key with
     * the `whatsapp:write` scope and, in a team workspace, an owner, admin or
     * member (`templates:write`).
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

        return $this->client->delete("/whatsapp/templates/" . rawurlencode($id));
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
 * WhatsApp resource: connect senders, manage their business profiles,
 * photos, ice breakers, commands and calling, manage Meta-reviewed message
 * templates, and check 24-hour conversation windows.
 *
 * WhatsApp is a first-class Sendly channel: connect a number you own, create
 * message templates, and send via
 * `$client->messages()->send(['channel' => 'whatsapp', ...])`.
 *
 * Connecting a number is a one-time $19 setup (no monthly fee). The first
 * number ends with a human step: {@see WhatsAppSignup::create()} returns a
 * `connectUrl` that a person must open in a browser and log in with Facebook
 * to link their WhatsApp Business Account. Hand the URL to your user; that
 * connection cannot be completed programmatically. Further numbers can join
 * an account that is already connected without it: pass the account's
 * `businessAccountId` to {@see WhatsAppSignup::create()} and submit the code
 * WhatsApp sends the number with {@see WhatsAppSignup::verify()}.
 *
 * Two ways to reach a recipient:
 *
 *   - Inside a 24-hour window (the recipient messaged you in the last 24h):
 *     free-form text and media are allowed. Check with {@see window()}.
 *   - Anytime: an approved template. Templates are reviewed by Meta
 *     (typically 24-48h) and categorized as authentication, utility, or
 *     marketing — pricing follows the category and destination country.
 *
 * Pricing: free-form text or media inside the 24-hour window costs 1 credit
 * each for the first 1,000 per sending number per calendar month (UTC), then
 * the destination's utility template price; countries without a listed
 * price use the default utility price of 12 credits. Templates are priced
 * by category and destination country; countries without a listed price use
 * 33 (marketing), 12 (utility) and 12 (authentication) credits. A failed
 * send gives its slot back.
 *
 * Scopes and keys: sends go through `messages()->send()` and need
 * `sms:send`, not `whatsapp:write`, and a live key. Reads (signup status,
 * templates, the window, senders and sender profiles) need `whatsapp:read`
 * and accept test keys. Signup, template create/edit/delete and profile
 * edits need `whatsapp:write` and a live key (`sk_live_v1_xxx`; otherwise
 * 403 `whatsapp_requires_live_key`). In a team workspace, connecting and
 * profile edits need an owner or admin (`settings:write`), and template
 * writes need an owner, admin or member (`templates:write`); a missing role
 * returns 403 `insufficient_permissions`. The profile photo, ice breakers
 * and commands, and calling follow the profile rules: reading the ice
 * breakers and commands needs `whatsapp:read`, and changing any of them
 * needs `whatsapp:write`, a live key and, in a team workspace, an owner or
 * admin. Adding a number by code ({@see WhatsAppSignup::verify()} and
 * {@see WhatsAppSignup::resend()}) follows the signup rules.
 *
 * WhatsApp is enabled per person: the user who owns the API key, not the
 * workspace. While it is off, sends return 403 `whatsapp_not_enabled` and
 * every method on this resource gets 404 `not_found`.
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
     * message). Outside a window, send an approved template. Needs the
     * `whatsapp:read` scope; test keys work.
     *
     * @param string $from Your WhatsApp-connected sending number, in E.164 format
     * @param string $to The recipient's number, in E.164 format
     * @return array{open: bool, expiresAt: ?string} Whether the window is
     *   open and when it closes (ISO 8601). After it closes `expiresAt` is
     *   the past expiry, with `open` false. It is null when Sendly has no
     *   window on record for the pair; a free-form send may still go through
     *   then if WhatsApp reports an open window, and otherwise fails with
     *   `whatsapp_window_closed`.
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
