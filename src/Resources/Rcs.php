<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

/**
 * Where an RCS registration is in its lifecycle, as reported in
 * `customerStage` on brands and agents and in the top-level `stage` of
 * {@see RcsRegistration::get()} and {@see RcsAgents::get()}.
 */
final class RcsCustomerStage
{
    public const DRAFT = 'draft';
    public const IN_REVIEW = 'in_review';
    public const CHANGES_REQUESTED = 'changes_requested';
    public const REJECTED = 'rejected';
    public const BRAND_VERIFICATION = 'brand_verification';
    public const AGENT_REVIEW = 'agent_review';
    public const TESTING = 'testing';
    public const LAUNCH_REVIEW = 'launch_review';
    public const LAUNCHING = 'launching';
    public const LAUNCH_REJECTED = 'launch_rejected';
    public const LIVE = 'live';
    public const SUSPENDED = 'suspended';
    public const FAILED = 'failed';
}

/**
 * The review state of an RCS brand or agent, as reported in `reviewStatus`.
 */
final class RcsReviewStatus
{
    public const DRAFT = 'draft';
    public const AWAITING_REVIEW = 'awaiting_review';
    public const CHANGES_REQUESTED = 'changes_requested';
    public const APPROVED_FOR_CARRIER = 'approved_for_carrier';
    public const REJECTED = 'rejected';
    public const LAUNCH_REQUESTED = 'launch_requested';
    public const LAUNCH_SUBMITTED = 'launch_submitted';
    public const LAUNCH_REJECTED = 'launch_rejected';
    public const FAILED = 'failed';
}

/**
 * Error codes the RCS registration endpoints respond with. Read them from
 * {@see \Sendly\Exceptions\SendlyException::getApiErrorCode()}.
 */
final class RcsErrorCode
{
    public const NOT_ENABLED = 'rcs_not_enabled';
    public const NOT_FOUND = 'rcs_not_found';
    public const FIELD_LOCKED = 'rcs_field_locked';
    public const US_ONLY = 'rcs_us_only';
    public const INVALID_CONTENT = 'rcs_invalid_content';
    public const BRAND_NOT_VERIFIED = 'rcs_brand_not_verified';
    public const LAUNCH_NOT_READY = 'rcs_launch_not_ready';
    public const INTERNAL_ERROR = 'rcs_internal_error';
    public const INSUFFICIENT_PERMISSIONS = 'insufficient_permissions';
    public const FORBIDDEN = 'forbidden';
}

/**
 * @phpstan-import-type RcsBrand from Rcs
 * @phpstan-import-type RcsAgent from Rcs
 * @phpstan-import-type RcsTestDevice from Rcs
 */
class RcsRegistration
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch your workspace's RCS registration at a glance: the newest agent,
     * its brand and test devices, and the stage the registration is at.
     *
     * `brand` and `agent` are null until you create them. `stage` is a
     * {@see RcsCustomerStage} value (`draft` when nothing exists yet).
     * `usEligible` is false when the business on file is outside the US.
     *
     * Requires an API key with the `rcs:read` scope. Until the RCS channel
     * is enabled for your account this throws `NotFoundException`
     * (`rcs_not_enabled`).
     *
     * @return array{brand: ?RcsBrand, agent: ?RcsAgent, devices: array<int, RcsTestDevice>, stage: string, usEligible: bool}
     */
    public function get(): array
    {
        return $this->client->get('/rcs/registration');
    }
}

/**
 * @phpstan-import-type RcsBrandInput from Rcs
 */
class RcsDossier
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch the business details Sendly already holds for your workspace,
     * shaped as a brand input so you can pass it straight to
     * {@see RcsBrands::create()} and fill in the rest.
     *
     * `source` says where the prefill came from: `tendlc` (your newest 10DLC
     * brand), `verification` (your active toll-free verification) or `none`
     * (nothing on file; `brand` is empty). Only non-empty fields are
     * returned. `usEligible` is false when something on file names a
     * non-US country.
     *
     * Requires an API key with the `rcs:read` scope.
     *
     * @return array{brand: RcsBrandInput, usEligible: bool, source: string}
     */
    public function get(): array
    {
        return $this->client->get('/rcs/dossier');
    }
}

/**
 * @phpstan-import-type RcsBrandInput from Rcs
 * @phpstan-import-type RcsBrand from Rcs
 */
class RcsBrands
{
    private const BRAND_KEYS = [
        'displayName',
        'legalName',
        'legalEntityType',
        'organizationType',
        'websiteUrl',
        'ein',
        'stockSymbol',
    ];
    private const ADDRESS_KEYS = ['line1', 'line2', 'city', 'state', 'postalCode', 'countryCode'];
    private const CONTACT_KEYS = ['firstName', 'lastName', 'title', 'email', 'phoneNumber'];

    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Create a brand draft: the business identity an RCS agent belongs to.
     * Step 1 of registering for RCS.
     *
     * Every field is optional here; completeness is checked when you submit
     * the agent with {@see RcsAgents::submit()}. `address.countryCode` must
     * be `US` when given (the API responds 422 `rcs_us_only` otherwise).
     * Keys outside the documented input are dropped. Start from
     * {@see RcsDossier::get()} to prefill what Sendly already knows.
     *
     * Requires an API key with the `rcs:write` scope. The POST carries an
     * `Idempotency-Key`; pass your own to make a retry from another process
     * return the brand created the first time instead of a second draft.
     *
     * @param RcsBrandInput $params Business identity. `legalEntityType` is
     *   one of `LIMITED_LIABILITY_COMPANY`, `SOLE_PROPRIETORSHIP`,
     *   `PARTNERSHIP`, `CORPORATION`, `S_CORPORATION`; `organizationType`
     *   one of `PRIVATE_PROFIT`, `PUBLIC_PROFIT`, `NON_PROFIT`,
     *   `GOVERNMENT`, `UNKNOWN`; `ein` is 9 digits (`12-3456789` accepted);
     *   `stockSymbol` is `EXCHANGE:TICKER`; URLs must be https.
     * @param string|null $idempotencyKey Idempotency key (1-255 printable
     *   ASCII characters). Overrides the auto-generated per-request key.
     * @return array{brand: RcsBrand} The new draft (`reviewStatus` `draft`).
     */
    public function create(array $params = [], ?string $idempotencyKey = null): array
    {
        return $this->client->post('/rcs/brands', self::body($params), $idempotencyKey);
    }

    /**
     * Patch a brand draft. Only the keys you pass change; `null` clears a
     * nullable field, and `address` / `contact` may be partial objects.
     *
     * Editing is refused with 409 `rcs_field_locked` while the registration
     * is under review or once the brand is registered on the carrier
     * network. Requires an API key with the `rcs:write` scope.
     *
     * @param string $id Brand ID
     * @param RcsBrandInput $params The fields to change
     * @return array{brand: RcsBrand} The updated brand
     * @throws ValidationException If ID is empty.
     */
    public function update(string $id, array $params): array
    {
        if (empty($id)) {
            throw new ValidationException('Brand ID is required');
        }

        return $this->client->patch('/rcs/brands/' . rawurlencode($id), self::body($params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function body(array $params): array
    {
        $body = RcsInput::pick($params, self::BRAND_KEYS);
        if (array_key_exists('address', $params)) {
            $body['address'] = RcsInput::pickObject($params['address'], self::ADDRESS_KEYS);
        }
        if (array_key_exists('contact', $params)) {
            $body['contact'] = RcsInput::pickObject($params['contact'], self::CONTACT_KEYS);
        }

        return $body;
    }
}

/**
 * @phpstan-import-type RcsAgent from Rcs
 * @phpstan-import-type RcsAgentBasicsInput from Rcs
 * @phpstan-import-type RcsCampaignInput from Rcs
 * @phpstan-import-type RcsTestingInput from Rcs
 * @phpstan-import-type RcsTestDevice from Rcs
 * @phpstan-import-type RcsTestDeviceInput from Rcs
 */
class RcsAgents
{
    private const BASICS_KEYS = [
        'displayName',
        'useCase',
        'description',
        'logoUrl',
        'heroUrl',
        'brandColor',
        'privacyPolicyUrl',
        'termsAndConditionsUrl',
    ];
    private const BASICS_OBJECT_KEYS = [
        'phoneNumber' => ['number', 'label'],
        'website' => ['url', 'label'],
        'email' => ['address', 'label'],
    ];
    private const CAMPAIGN_KEYS = [
        'companyOverview',
        'agentOverview',
        'additionalInformation',
        'interactions',
        'messageExamples',
    ];
    private const CONSENT_KEYS = [
        'optInMethods',
        'callToAction',
        'callToActionUrl',
        'callToActionMediaUrl',
        'doubleOptIn',
        'doubleOptInMessage',
        'optInMessage',
        'helpResponse',
        'optOutResponse',
    ];
    private const TESTING_KEYS = ['testUrl', 'messageId', 'additionalInformation'];

    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List your RCS agents.
     *
     * Returns the RCS agents registered for your workspace, newest first.
     * An empty list means no agent is set up yet — register one from the
     * RCS section of your dashboard or with {@see create()}.
     *
     * Agent `status` is `draft`, `submitted`, `testing` (can send, but only
     * to invited test devices), `approved` (can send to everyone), or
     * `suspended`. `sendable` is true when the agent is fully provisioned
     * and its status allows sending — only sendable agents can be used with
     * `messages()->send(['channel' => 'rcs', ...])`. `stage` is the
     * registration's {@see RcsCustomerStage}.
     *
     * @return array{agents: array<array{id: string, name: string, status: string, useCase: ?string, sendable: bool, stage: string, createdAt: string}>}
     */
    public function list(): array
    {
        return $this->client->get('/rcs/agents');
    }

    /**
     * Create an agent draft under a brand: the name, branding and use case
     * recipients see. Step 2 of registering for RCS.
     *
     * Only `brandId` is required here; completeness is checked by
     * {@see submit()}. `displayName` and `useCase` at the top level override
     * the same keys in `basics`. Logo, hero and call-to-action media cannot
     * be uploaded over the API: `basics.logoUrl`, `basics.heroUrl` and
     * `campaign.consentSettings.callToActionMediaUrl` must be public
     * `https://` URLs (upload files from the dashboard instead). Keys
     * outside the documented input are dropped.
     *
     * Requires an API key with the `rcs:write` scope. The POST carries an
     * `Idempotency-Key`; pass your own to make a retry from another process
     * return the agent created the first time instead of a second draft.
     *
     * @param array{
     *   brandId: string,
     *   displayName?: ?string,
     *   useCase?: ?string,
     *   basics?: RcsAgentBasicsInput,
     *   campaign?: ?RcsCampaignInput,
     *   testing?: ?RcsTestingInput
     * } $params `useCase` is one of `MULTI_USE`, `PROMOTIONAL`,
     *   `TRANSACTIONAL`, `OTP`.
     * @param string|null $idempotencyKey Idempotency key (1-255 printable
     *   ASCII characters). Overrides the auto-generated per-request key.
     * @return array{agent: RcsAgent} The new draft (`status` and
     *   `reviewStatus` `draft`, no test devices yet).
     * @throws ValidationException If `brandId` is empty.
     */
    public function create(array $params, ?string $idempotencyKey = null): array
    {
        if (empty($params['brandId'])) {
            throw new ValidationException('brandId is required');
        }

        $body = ['brandId' => $params['brandId']] + self::body($params);

        return $this->client->post('/rcs/agents', $body, $idempotencyKey);
    }

    /**
     * Fetch one agent with its test devices and registration stage.
     *
     * `devices` and `stage` repeat `agent.testDevices` and
     * `agent.customerStage` for convenience. Requires an API key with the
     * `rcs:read` scope.
     *
     * @param string $id Agent ID
     * @return array{agent: RcsAgent, devices: array<int, RcsTestDevice>, stage: string}
     * @throws ValidationException If ID is empty.
     */
    public function get(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Agent ID is required');
        }

        return $this->client->get('/rcs/agents/' . rawurlencode($id));
    }

    /**
     * Patch an agent draft. Only the groups you pass change: `displayName`,
     * `useCase` and `basics` are merged into the basics; `campaign` and
     * `testing` are merged section-wise, and passing `'campaign' => null` or
     * `'testing' => null` clears that section.
     *
     * Media URLs follow the same public-`https://` rule as {@see create()}.
     * Editing is refused with 409 `rcs_field_locked` while the registration
     * is under review, and each section locks once it has been submitted.
     * Requires an API key with the `rcs:write` scope.
     *
     * @param string $id Agent ID
     * @param array{
     *   displayName?: ?string,
     *   useCase?: ?string,
     *   basics?: RcsAgentBasicsInput,
     *   campaign?: ?RcsCampaignInput,
     *   testing?: ?RcsTestingInput
     * } $params The groups to change
     * @return array{agent: RcsAgent} The updated agent
     * @throws ValidationException If ID is empty.
     */
    public function update(string $id, array $params): array
    {
        if (empty($id)) {
            throw new ValidationException('Agent ID is required');
        }

        return $this->client->patch('/rcs/agents/' . rawurlencode($id), self::body($params));
    }

    /**
     * Replace the agent's test devices: the handsets that receive RCS from
     * it while it is in `testing`. The list is authoritative; numbers you
     * leave out are removed and new ones are invited. Up to 20 devices.
     *
     * Each entry is an E.164 number (a formatted 10-digit US number is
     * accepted and normalised), or an array with `phoneNumber` and an
     * optional `label`. Requires an API key with the `rcs:write` scope.
     *
     * @param string $id Agent ID
     * @param array<int, string|RcsTestDeviceInput> $devices The full list
     *   after the change; an empty array removes every device.
     * @return array{devices: array<int, RcsTestDevice>} The devices now on
     *   the agent. `inviteStatus` is null until the invite has gone out.
     * @throws ValidationException If ID is empty or an entry is malformed.
     */
    public function setTestDevices(string $id, array $devices): array
    {
        if (empty($id)) {
            throw new ValidationException('Agent ID is required');
        }

        $list = [];
        foreach ($devices as $device) {
            if (is_string($device)) {
                $list[] = ['phoneNumber' => $device];
            } elseif (is_array($device)) {
                $list[] = RcsInput::pick($device, ['phoneNumber', 'label']);
            } else {
                throw new ValidationException(
                    "Each test device must be a phone number string or an array with 'phoneNumber' and optional 'label'"
                );
            }
        }

        return $this->client->put('/rcs/agents/' . rawurlencode($id) . '/test-devices', ['devices' => $list]);
    }

    /**
     * Submit the brand and agent for review. Step 3 of registering for RCS.
     *
     * Sendly reviews the registration first and then sends it on to the
     * carrier network, which verifies the business and reviews the agent.
     * Watch progress with {@see get()} or {@see RcsRegistration::get()}:
     * `reviewStatus` moves to `awaiting_review` now and the stage to
     * `in_review`; a review can also come back `changes_requested` with a
     * `reviewNote` explaining what to fix.
     *
     * Responds 422 `rcs_invalid_content` (with `errors` listing each
     * incomplete `brand.*` / `agent.*` field) when the drafts are not
     * complete, and 409 `rcs_field_locked` when the agent was already
     * submitted. Requires an API key with the `rcs:write` scope.
     *
     * @param string $id Agent ID
     * @param string|null $idempotencyKey Idempotency key (1-255 printable
     *   ASCII characters). Overrides the auto-generated per-request key; a
     *   replay returns the original response without notifying reviewers
     *   again.
     * @return array{agent: RcsAgent, stage: string}
     * @throws ValidationException If ID is empty.
     */
    public function submit(string $id, ?string $idempotencyKey = null): array
    {
        if (empty($id)) {
            throw new ValidationException('Agent ID is required');
        }

        return $this->client->post('/rcs/agents/' . rawurlencode($id) . '/submit', [], $idempotencyKey);
    }

    /**
     * Ask to launch an agent that has finished testing. Step 4 of
     * registering for RCS.
     *
     * Available once the agent is in `testing` and the campaign details
     * (`campaign`) are filled in: an agent overview, at least one
     * interaction, at least three message examples, consent settings, and
     * a `testUrl` showing the agent in use. Responds 409
     * `rcs_launch_not_ready` before that, 422 `rcs_invalid_content` (with
     * `campaign.*` / `testing.*` `errors`) when details are missing, and
     * 409 `rcs_field_locked` when a launch is already under review.
     * Afterwards `reviewStatus` is `launch_requested` and the stage
     * `launch_review`. Requires an API key with the `rcs:write` scope.
     *
     * @param string $id Agent ID
     * @param array{testUrl?: string, testingAdditionalInformation?: string} $params
     *   Testing details to store before the request is filed. Optional when
     *   the agent's `testing` section already has them.
     * @param string|null $idempotencyKey Idempotency key (1-255 printable
     *   ASCII characters). Overrides the auto-generated per-request key.
     * @return array{agent: RcsAgent, stage: string}
     * @throws ValidationException If ID is empty.
     */
    public function requestLaunch(string $id, array $params = [], ?string $idempotencyKey = null): array
    {
        if (empty($id)) {
            throw new ValidationException('Agent ID is required');
        }

        return $this->client->post(
            '/rcs/agents/' . rawurlencode($id) . '/request-launch',
            RcsInput::pick($params, ['testUrl', 'testingAdditionalInformation']),
            $idempotencyKey
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function body(array $params): array
    {
        $body = RcsInput::pick($params, ['displayName', 'useCase']);
        if (array_key_exists('basics', $params)) {
            $body['basics'] = self::basics($params['basics']);
        }
        if (array_key_exists('campaign', $params)) {
            $body['campaign'] = self::campaign($params['campaign']);
        }
        if (array_key_exists('testing', $params)) {
            $body['testing'] = RcsInput::pickObject($params['testing'], self::TESTING_KEYS);
        }

        return $body;
    }

    private static function basics(mixed $basics): mixed
    {
        if (!is_array($basics)) {
            return $basics;
        }

        $body = RcsInput::pick($basics, self::BASICS_KEYS);
        foreach (self::BASICS_OBJECT_KEYS as $key => $keys) {
            if (array_key_exists($key, $basics)) {
                $body[$key] = RcsInput::pickObject($basics[$key], $keys);
            }
        }

        return $body;
    }

    private static function campaign(mixed $campaign): mixed
    {
        if (!is_array($campaign)) {
            return $campaign;
        }

        $body = RcsInput::pick($campaign, self::CAMPAIGN_KEYS);
        if (array_key_exists('consentSettings', $campaign)) {
            $body['consentSettings'] = RcsInput::pickObject($campaign['consentSettings'], self::CONSENT_KEYS);
        }

        return $body;
    }
}

/**
 * Builds RCS request bodies from caller arrays, keeping only documented keys
 * and preserving explicit nulls (which clear a field on PATCH).
 *
 * @internal
 */
final class RcsInput
{
    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public static function pick(array $source, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $out[$key] = $source[$key];
            }
        }

        return $out;
    }

    /**
     * @param array<int, string> $keys
     */
    public static function pickObject(mixed $value, array $keys): mixed
    {
        return is_array($value) ? self::pick($value, $keys) : $value;
    }
}

/**
 * RCS resource — register your brand and agent, discover your agents, and
 * pre-flight recipient capability.
 *
 * RCS is a first-class Sendly channel: send branded rich messages — text
 * with suggested replies and actions, or rich cards — via
 * `$client->messages()->send(['channel' => 'rcs', ...])`.
 *
 * Registration is self-serve, from the RCS section of your dashboard or
 * over this API, and follows one path:
 *
 *   1. Brand — create the business identity with {@see RcsBrands::create()}
 *      (prefill it from {@see RcsDossier::get()}). US businesses only for
 *      now.
 *   2. Agent — create the sender recipients see with
 *      {@see RcsAgents::create()}: name, logo, hero image, colour, links,
 *      use case. Media must already be hosted at public `https://` URLs;
 *      file upload is dashboard-only.
 *   3. Submit — {@see RcsAgents::submit()} sends both for review. Sendly
 *      reviews the registration first, then the carrier network verifies
 *      the business and reviews the agent. Follow progress with
 *      {@see RcsRegistration::get()}; a review may come back
 *      `changes_requested` with a note.
 *   4. Test and launch — once the agent is in `testing`, invite handsets
 *      with {@see RcsAgents::setTestDevices()}, send to them, then file the
 *      campaign details and {@see RcsAgents::requestLaunch()}. After launch
 *      review the agent goes `live` and can message every RCS-capable
 *      recipient.
 *
 * Delivery is per-recipient: not every device or network supports RCS.
 * Text messages fall back to plain SMS automatically (billed as SMS)
 * unless you pass `'fallbackToSms' => false`; rich cards have no SMS form
 * and only deliver to RCS-capable recipients. Use {@see capability()} to
 * check a recipient before sending.
 *
 * Registration reads need an API key with the `rcs:read` scope and writes
 * the `rcs:write` scope (live or test keys). RCS sends and capability
 * checks require a live API key (`sk_live_v1_xxx`). Until the RCS channel
 * is enabled for your account every RCS endpoint reads as absent and
 * throws `NotFoundException`.
 *
 * @phpstan-type RcsAddressInput array{line1?: ?string, line2?: ?string, city?: ?string, state?: ?string, postalCode?: ?string, countryCode?: ?string}
 * @phpstan-type RcsContactInput array{firstName?: ?string, lastName?: ?string, title?: ?string, email?: ?string, phoneNumber?: ?string}
 * @phpstan-type RcsBrandInput array{displayName?: ?string, legalName?: ?string, legalEntityType?: ?string, organizationType?: ?string, websiteUrl?: ?string, ein?: ?string, stockSymbol?: ?string, address?: ?RcsAddressInput, contact?: ?RcsContactInput}
 * @phpstan-type RcsAgentBasicsInput array{displayName?: ?string, useCase?: ?string, description?: ?string, logoUrl?: ?string, heroUrl?: ?string, brandColor?: ?string, privacyPolicyUrl?: ?string, termsAndConditionsUrl?: ?string, phoneNumber?: ?array{number?: ?string, label?: ?string}, website?: ?array{url?: ?string, label?: ?string}, email?: ?array{address?: ?string, label?: ?string}}
 * @phpstan-type RcsInteractionInput array{interactionType: string, description?: ?string}
 * @phpstan-type RcsOptInMethodInput array{methodType: string, description?: ?string}
 * @phpstan-type RcsConsentSettingsInput array{optInMethods?: ?array<int, RcsOptInMethodInput>, callToAction?: ?string, callToActionUrl?: ?string, callToActionMediaUrl?: ?string, doubleOptIn?: ?bool, doubleOptInMessage?: ?string, optInMessage?: ?string, helpResponse?: ?string, optOutResponse?: ?string}
 * @phpstan-type RcsCampaignInput array{companyOverview?: ?string, agentOverview?: ?string, additionalInformation?: ?string, interactions?: ?array<int, RcsInteractionInput>, messageExamples?: ?array<int, string>, consentSettings?: ?RcsConsentSettingsInput}
 * @phpstan-type RcsTestingInput array{testUrl?: ?string, messageId?: ?string, additionalInformation?: ?string}
 * @phpstan-type RcsTestDeviceInput array{phoneNumber: string, label?: ?string}
 * @phpstan-type RcsBrand array{id: string, reviewStatus: string, customerStage: string, displayName: string, legalName: string, legalEntityType: string, organizationType: string, stockSymbol: ?string, websiteUrl: string, ein: string, address: array{line1: string, line2: ?string, city: string, state: string, postalCode: string, countryCode: string}, contact: array{firstName: string, lastName: string, title: ?string, email: string, phoneNumber: string}, reviewNote: ?string, rejectionReason: ?string, submittedForReviewAt: ?string, sentToCarrierAt: ?string, verifiedAt: ?string, createdAt: string, updatedAt: string}
 * @phpstan-type RcsTestDevice array{id: string, phoneNumber: string, label: ?string, inviteStatus: ?string, createdAt: string}
 * @phpstan-type RcsAgentBasics array{displayName: string, useCase: ?string, hostingRegion: ?string, description?: ?string, logoUrl?: ?string, heroUrl?: ?string, brandColor?: ?string, privacyPolicyUrl?: ?string, termsAndConditionsUrl?: ?string, phoneNumber?: ?array{number?: ?string, label?: ?string}, website?: ?array{url?: ?string, label?: ?string}, email?: ?array{address?: ?string, label?: ?string}}
 * @phpstan-type RcsAgent array{id: string, brandId: ?string, status: string, reviewStatus: string, customerStage: string, displayName: string, useCase: ?string, hostingRegion: ?string, basics: RcsAgentBasics, campaign: ?RcsCampaignInput, testing: ?RcsTestingInput, reviewNote: ?string, rejectionReason: ?string, testDevices: array<int, RcsTestDevice>, submittedForReviewAt: ?string, basicsSubmittedAt: ?string, launchSubmittedAt: ?string, liveAt: ?string, createdAt: string, updatedAt: string}
 */
class Rcs
{
    private Sendly $client;
    public RcsAgents $agents;
    public RcsRegistration $registration;
    public RcsDossier $dossier;
    public RcsBrands $brands;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
        $this->agents = new RcsAgents($client);
        $this->registration = new RcsRegistration($client);
        $this->dossier = new RcsDossier($client);
        $this->brands = new RcsBrands($client);
    }

    /**
     * Check whether a recipient can receive RCS.
     *
     * Probes the recipient's device and network. When `capable` is false, a
     * text send to this recipient falls back to plain SMS (unless the
     * fallback is disabled) and a card send fails with
     * `rcs_not_supported_for_recipient`. Requires a live API key.
     *
     * @param string $to The recipient's number, in E.164 format
     * @param string|null $agentId The agent to probe with. Optional when
     *   your workspace has exactly one sendable agent; required (the API
     *   responds 400 `rcs_agent_ambiguous`) when it has more.
     * @return array{to: string, agentId: string, capable: bool, features: array<int, string>}
     *   Whether the recipient is RCS-capable, and the feature tags their
     *   device reports.
     * @throws ValidationException If the number is not E.164.
     */
    public function capability(string $to, ?string $agentId = null): array
    {
        $this->validatePhone($to);

        $params = ['to' => $to];
        if ($agentId !== null) {
            $params['agentId'] = $agentId;
        }

        return $this->client->get('/rcs/capability', $params);
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
