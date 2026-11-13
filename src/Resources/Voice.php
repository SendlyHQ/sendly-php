<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

/**
 * How a number answers phone calls, as reported in `voiceMode` on a voice
 * number and accepted by {@see VoiceNumbers::update()}.
 *
 * `NONE` means voice is off, `RING_DASHBOARD` rings the team in the
 * dashboard, and `AGENT` has an AI agent answer.
 */
final class VoiceMode
{
    public const NONE = 'none';
    public const RING_DASHBOARD = 'ring_dashboard';
    public const AGENT = 'agent';
}

/**
 * @phpstan-import-type VoiceNumber from Voice
 * @phpstan-import-type UpdateVoiceNumberRequest from Voice
 * @phpstan-import-type RegisterEmergencyAddressRequest from Voice
 */
class VoiceNumbers
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List your workspace's active numbers with their voice settings, in the
     * same order as the dashboard.
     *
     * Requires an API key with the `calls:read` scope.
     *
     * @return array{data: array<int, VoiceNumber>}
     */
    public function list(): array
    {
        return $this->client->get('/voice/numbers');
    }

    /**
     * Fetch one number's voice settings.
     *
     * A number that is not active in your workspace throws
     * `NotFoundException` (`number_not_found`).
     *
     * Requires an API key with the `calls:read` scope.
     *
     * @param string $number The number's ID or its E.164 phone number
     * @return VoiceNumber
     * @throws ValidationException If the number is empty.
     */
    public function get(string $number): array
    {
        return $this->client->get(self::path($number));
    }

    /**
     * Change how a number answers phone calls.
     *
     * This changes what happens when real people call the number. Pass only
     * what changes. `voiceEnabled` switches voice on or off: turning it on
     * connects the number for calls and answers in `ring_dashboard` mode
     * unless `voiceMode` is `agent`, and turning it off sets `voiceMode` to
     * `none`. `voiceMode` is a {@see VoiceMode}, and a mode alone is enough:
     * `ring_dashboard` or `agent` switches voice on and `none` switches it
     * off. When both are sent, `voiceEnabled` wins. `agentId` is the agent
     * that answers in `agent` mode; `null` clears it. Other keys are dropped.
     *
     * Refusals you should handle, a mode sent on its own included: 400
     * `agent_required` (`agent` mode with no agent), 404 `agent_not_found`,
     * 409 `agent_disabled` (switch the agent on first), 503
     * `voice_attach_failed` (voice could not be switched on; try again) and
     * 503 `voice_unavailable`. A wrongly typed field is 400
     * `invalid_request` and an unknown mode 400 `invalid_voice_mode`, both
     * thrown as `ValidationException`.
     *
     * Requires a live API key with the `calls:write` scope. In a team
     * workspace the key's role must be able to change settings.
     *
     * @param string $number The number's ID or its E.164 phone number
     * @param UpdateVoiceNumberRequest $params The settings to change
     * @return VoiceNumber The number after the change.
     * @throws ValidationException If the number is empty.
     */
    public function update(string $number, array $params): array
    {
        $path = self::path($number);

        $body = [];
        foreach (['voiceEnabled', 'voiceMode'] as $key) {
            if (isset($params[$key])) {
                $body[$key] = $params[$key];
            }
        }
        if (array_key_exists('agentId', $params)) {
            $body['agentId'] = $params['agentId'];
        }

        return $this->client->patch($path, $body);
    }

    /**
     * Register the street address emergency services are sent to when
     * someone calls them from this number.
     *
     * A US or Canadian number needs one before it can place calls. The first
     * registration adds $1.50 a month to the number; registering again
     * replaces the address without adding the charge a second time. The
     * returned number's `emergencyAddress.status` is `provisioning` until the
     * registration is in place, then `active`.
     *
     * `state` is the two-letter state or province code, `zip` a five-digit
     * ZIP (or ZIP+4) in the US or a postal code like `A1A 1A1` in Canada, and
     * `country` is `US` (the default) or `CA`. A `unit` or `country` that is
     * not a string is 400 `invalid_request`, a missing or malformed field 400
     * `invalid_address`, and a number outside the US and Canada 400
     * `e911_not_applicable`, all thrown as `ValidationException`. An address
     * that could not be validated is 422 `invalid_address`, also a
     * `ValidationException`, with a corrected address (or null) under
     * `suggested` in {@see \Sendly\Exceptions\SendlyException::getResponseBody()}.
     * A refused registration is 503 `carrier_refused`; try again in a moment,
     * unless the message says the number couldn't be found for emergency
     * registration, which retrying won't fix: contact support.
     *
     * Requires a live API key with the `calls:write` scope. In a team
     * workspace the key's role must be able to change settings. The POST
     * carries an `Idempotency-Key`; pass your own to make a retry from
     * another process return the first registration.
     *
     * @param string $number The number's ID or its E.164 phone number
     * @param RegisterEmergencyAddressRequest $params `street`, `city`,
     *   `state` and `zip` are required.
     * @param string|null $idempotencyKey Idempotency key (1-255 printable
     *   ASCII characters). Overrides the auto-generated per-request key.
     * @return VoiceNumber The number with its `emergencyAddress`.
     * @throws ValidationException If the number is empty, or `street`,
     *   `city`, `state` or `zip` is empty or not a string.
     */
    public function registerEmergencyAddress(string $number, array $params, ?string $idempotencyKey = null): array
    {
        $path = self::path($number) . '/emergency-address';

        foreach (['street', 'city', 'state', 'zip'] as $key) {
            if (empty($params[$key]) || self::isBlank($params[$key])) {
                throw new ValidationException($key . ' is required');
            }
        }

        $body = array_filter([
            'street' => $params['street'],
            'unit' => $params['unit'] ?? null,
            'city' => $params['city'],
            'state' => $params['state'],
            'zip' => $params['zip'],
            'country' => $params['country'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->post($path, $body, $idempotencyKey);
    }

    /**
     * @throws ValidationException
     */
    private static function path(string $number): string
    {
        if (trim($number) === '') {
            throw new ValidationException('Number is required');
        }

        return '/voice/numbers/' . rawurlencode($number);
    }

    private static function isBlank(mixed $value): bool
    {
        return !is_string($value) || trim($value) === '';
    }
}

/**
 * @phpstan-import-type VoiceAgent from Voice
 * @phpstan-import-type CreateVoiceAgentRequest from Voice
 * @phpstan-import-type UpdateVoiceAgentRequest from Voice
 * @phpstan-import-type DeletedVoiceAgent from Voice
 */
class VoiceAgents
{
    private const AGENT_KEYS = ['name', 'enabled', 'kind', 'voice', 'language', 'greeting', 'instructions'];

    private const CONNECTION_MAP_KEYS = ['headers', 'customParameters'];

    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List your workspace's AI agents with their call stats.
     *
     * Requires an API key with the `calls:read` scope.
     *
     * @return array{data: array<int, VoiceAgent>}
     */
    public function list(): array
    {
        return $this->client->get('/voice/agents');
    }

    /**
     * Create an AI agent.
     *
     * The agent answers real callers on any number pointed at it and talks
     * on the calls you place with it. Each agent gets its own scoped sending
     * key so it can text callers; `canSendSms` says whether it has one. A
     * workspace can have up to 20 agents (409 `agent_limit` after that).
     *
     * `name` is 1 to 80 characters. `enabled` defaults to true. `voice` is an
     * id from {@see VoiceVoices::list()}; an unknown id falls back to the
     * default voice. `language` defaults to `en-US`. `greeting` (up to 500
     * characters) is what the agent says when it picks up, and
     * `instructions` (up to 4000) the business instructions it follows.
     * `tools.sendSms` (default true) lets the agent text the caller during
     * the call. `tools.transferTo` (an E.164 number, default null) is the
     * number a caller who asks for a person is transferred to, and where
     * {@see Calls::transfer()} sends the caller when you leave out `to`.
     *
     * `kind` is a {@see VoiceAgentKind}: `sendly` (the default), or one of
     * your own agents, `custom_llm`, `sip` or `websocket`, which need
     * `connection` and the workspace's bring-your-own-agent feature (403
     * `byo_agents_not_enabled` otherwise; support switches it on).
     * `connection` matches the kind and is sent as given:
     * `custom_llm` takes `baseUrl` (an `https://` URL; Sendly POSTs
     * `{baseUrl}/chat/completions` with streaming), `model`, `apiKey` (sent
     * as `Authorization: Bearer`) and `headers` (up to 10, with secret
     * values); `sip` takes `uri` (such as `sip:+15125550100@sip.example.com`),
     * `transport` (`auto`, `udp`, `tcp` or `tls`; default `tcp`),
     * `mediaEncryption` (`disable`, `allow` or `require`; default `allow`),
     * `username`, `password` and `headers` (up to 10 named `X-...`);
     * `websocket` takes `url` (a `wss://` URL), `audioFormat`
     * (`pcm16_16000`, the default, `pcm16_24000`, `pcm16_8000` or
     * `mulaw_8000`), `authToken` (sent as `Authorization: Bearer`) and
     * `customParameters` (up to 20 values sent in the `start` message).
     * Hosts must resolve to public internet addresses. Secrets are never
     * returned: the agent's `connection` reports `hasApiKey`, `headerNames`,
     * `hasPassword` or `hasAuthToken` instead.
     *
     * Your own agent comes back with `signingSecret`, shown this once: save
     * it now, it is never shown again. Sendly signs every request to your
     * agent with it in `X-Sendly-Signature` (`t=<unix>,v1=<hex HMAC-SHA256>`).
     *
     * Other keys are dropped; a field the API rejects is 400
     * `invalid_request`, thrown as `ValidationException` naming the field
     * (`connection.<field>: <reason>` for a connection rule).
     *
     * Requires a live API key with the `calls:write` scope. In a team
     * workspace the key's role must be able to manage API keys. The POST
     * carries an `Idempotency-Key`; pass your own to make a retry from
     * another process return the agent created the first time.
     *
     * @param CreateVoiceAgentRequest $params `name` is required.
     * @param string|null $idempotencyKey Idempotency key (1-255 printable
     *   ASCII characters). Overrides the auto-generated per-request key.
     * @return VoiceAgent The new agent, with `signingSecret` when it is one
     *   of your own.
     * @throws ValidationException If `name` is empty.
     */
    public function create(array $params, ?string $idempotencyKey = null): array
    {
        if (empty($params['name']) || self::isBlank($params['name'])) {
            throw new ValidationException('name is required');
        }

        return $this->client->post('/voice/agents', self::body($params), $idempotencyKey);
    }

    /**
     * Fetch one agent.
     *
     * An agent that is not in your workspace throws `NotFoundException`
     * (`agent_not_found`).
     *
     * Requires an API key with the `calls:read` scope.
     *
     * @param string $id Agent ID
     * @return VoiceAgent
     * @throws ValidationException If ID is empty.
     */
    public function get(string $id): array
    {
        return $this->client->get(self::path($id));
    }

    /**
     * Update an agent.
     *
     * Pass only the fields that change; `tools` keys you leave out keep
     * their current values. An empty `greeting` or `instructions` clears it
     * and an empty `language` resets it to `en-US`. Changes apply to the next
     * call the agent takes.
     *
     * With the same `kind`, `connection` is merged into the saved one: keys
     * you leave out keep their values, a secret (`apiKey`, `password`,
     * `authToken`) set to null is removed, and `headers` or
     * `customParameters` replace the saved set (null removes them).
     * Switching to one of your own kinds needs a full `connection` and
     * returns a new `signingSecret`; switching to `sendly` removes the
     * connection and the signing secret. `rotateSigningSecret` set to true
     * makes a new signing secret, returned once in `signingSecret`, and the
     * old one stops working at once; it is refused (400 `invalid_request`)
     * on a `sendly` agent, and any other value is not sent.
     *
     * Requires a live API key with the `calls:write` scope. In a team
     * workspace the key's role must be able to manage API keys.
     *
     * @param string $id Agent ID
     * @param UpdateVoiceAgentRequest $params Any subset of the create fields,
     *   and `rotateSigningSecret`
     * @return VoiceAgent The agent after the change, with `signingSecret`
     *   when one was just made: save it now, it is never shown again.
     * @throws ValidationException If ID is empty.
     */
    public function update(string $id, array $params): array
    {
        $body = self::body($params);
        if (($params['rotateSigningSecret'] ?? null) === true) {
            $body['rotateSigningSecret'] = true;
        }

        return $this->client->patch(self::path($id), $body);
    }

    /**
     * Delete an agent and revoke its sending key.
     *
     * An agent that still answers a number cannot be deleted: the API
     * responds 409 `agent_in_use` and lists those numbers under `numbers` in
     * {@see \Sendly\Exceptions\SendlyException::getResponseBody()}. Point
     * them at another agent or back to the team first with
     * {@see VoiceNumbers::update()}.
     *
     * Requires a live API key with the `calls:write` scope. In a team
     * workspace the key's role must be able to manage API keys.
     *
     * @param string $id Agent ID
     * @return DeletedVoiceAgent Deletion confirmation.
     * @throws ValidationException If ID is empty.
     */
    public function delete(string $id): array
    {
        return $this->client->delete(self::path($id));
    }

    /**
     * @throws ValidationException
     */
    private static function path(string $id): string
    {
        if (trim($id) === '') {
            throw new ValidationException('Agent ID is required');
        }

        return '/voice/agents/' . rawurlencode($id);
    }

    private static function isBlank(mixed $value): bool
    {
        return !is_string($value) || trim($value) === '';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function body(array $params): array
    {
        $body = [];
        foreach (self::AGENT_KEYS as $key) {
            if (isset($params[$key])) {
                $body[$key] = $params[$key];
            }
        }

        if (is_array($params['tools'] ?? null)) {
            $tools = [];
            if (isset($params['tools']['sendSms'])) {
                $tools['sendSms'] = $params['tools']['sendSms'];
            }
            if (array_key_exists('transferTo', $params['tools'])) {
                $tools['transferTo'] = $params['tools']['transferTo'];
            }
            if ($tools !== []) {
                $body['tools'] = $tools;
            }
        }

        if (is_array($params['connection'] ?? null) && $params['connection'] !== []) {
            $connection = $params['connection'];
            foreach (self::CONNECTION_MAP_KEYS as $key) {
                if (($connection[$key] ?? null) === []) {
                    $connection[$key] = new \stdClass();
                }
            }
            $body['connection'] = $connection;
        }

        return $body;
    }
}

/**
 * @phpstan-import-type AgentVoice from Voice
 */
class VoiceVoices
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List the voices an agent can speak with. Pass a voice's `id` as
     * `voice` when you create or update an agent.
     *
     * Requires an API key with the `calls:read` scope.
     *
     * @return array{data: array<int, AgentVoice>}
     */
    public function list(): array
    {
        return $this->client->get('/voice/voices');
    }
}

/**
 * Voice resource: configure numbers, AI agents and voices for phone calls.
 *
 * Everything a call placed with {@see Calls} depends on, configured from
 * code: switch voice on for a number and choose how it answers with
 * {@see VoiceNumbers::update()}, register the number's emergency address
 * with {@see VoiceNumbers::registerEmergencyAddress()}, and create the AI
 * agents that talk with {@see VoiceAgents::create()}. A number is addressed
 * by its ID or its E.164 phone number.
 *
 * Reads need an API key with the `calls:read` scope and writes
 * `calls:write`; writes also need a live key (a test key gets 403
 * `live_key_required`). In a team workspace, changing a number or its
 * emergency address also needs a role that can change settings, and
 * managing agents a role that can manage API keys (each agent holds its own
 * scoped sending key); otherwise the API responds 403 `forbidden`. Voice is
 * enabled workspace by workspace; until it is enabled for your account every
 * method throws `NotFoundException` (`voice_not_enabled`). Error codes are
 * in {@see CallErrorCode}.
 *
 * An agent is Sendly's own (`kind` `sendly`) or one of yours that Sendly
 * reaches over a `connection` (`custom_llm`, `sip` or `websocket`; see
 * {@see VoiceAgentKind}), behind the workspace's bring-your-own-agent
 * feature.
 *
 * @phpstan-type EmergencyAddress array{street: string, unit?: string, city: string, state: string, zip: string, country: string}
 * @phpstan-type VoiceNumberEmergencyAddress array{status: string, address: ?EmergencyAddress}
 * @phpstan-type VoiceNumberRates array{inbound: int, outbound: int, agent: int}
 * @phpstan-type VoiceNumber array{id: string, object: string, phoneNumber: string, phoneNumberType: ?string, countryCode: ?string, isDefault: bool, voiceEnabled: bool, voiceMode: string, agentId: ?string, emergencyAddress: ?VoiceNumberEmergencyAddress, ratePerMinute: VoiceNumberRates}
 * @phpstan-type UpdateVoiceNumberRequest array{voiceEnabled?: bool, voiceMode?: string, agentId?: ?string}
 * @phpstan-type RegisterEmergencyAddressRequest array{street: string, unit?: string, city: string, state: string, zip: string, country?: string}
 * @phpstan-type VoiceAgentTools array{sendSms: bool, transferTo: ?string}
 * @phpstan-type VoiceAgentCustomLlmConnection array{baseUrl: string, model: string, headerNames: array<int, string>, hasApiKey: bool}
 * @phpstan-type VoiceAgentSipConnection array{uri: string, transport: string, mediaEncryption: string, username: ?string, hasPassword: bool, headers: array<string, string>}
 * @phpstan-type VoiceAgentWebsocketConnection array{url: string, audioFormat: string, hasAuthToken: bool, customParameters: array<string, string>}
 * @phpstan-type VoiceAgent array{id: string, object: string, name: string, enabled: bool, kind: string, connection: VoiceAgentCustomLlmConnection|VoiceAgentSipConnection|VoiceAgentWebsocketConnection|null, hasSigningSecret: bool, signingSecret?: string, voice: string, voiceLabel: string, language: string, greeting: string, instructions: string, tools: VoiceAgentTools, canSendSms: bool, callsHandled: int, avgDurationSecs: int, createdAt: string, updatedAt: string}
 * @phpstan-type VoiceAgentCustomLlmConnectionInput array{baseUrl?: string, model?: string, apiKey?: ?string, headers?: ?array<string, string>}
 * @phpstan-type VoiceAgentSipConnectionInput array{uri?: string, transport?: string, mediaEncryption?: string, username?: ?string, password?: ?string, headers?: ?array<string, string>}
 * @phpstan-type VoiceAgentWebsocketConnectionInput array{url?: string, audioFormat?: string, authToken?: ?string, customParameters?: ?array<string, string>}
 * @phpstan-type CreateVoiceAgentRequest array{name: string, enabled?: bool, kind?: string, connection?: VoiceAgentCustomLlmConnectionInput|VoiceAgentSipConnectionInput|VoiceAgentWebsocketConnectionInput, voice?: string, language?: string, greeting?: string, instructions?: string, tools?: array{sendSms?: bool, transferTo?: ?string}}
 * @phpstan-type UpdateVoiceAgentRequest array{name?: string, enabled?: bool, kind?: string, connection?: VoiceAgentCustomLlmConnectionInput|VoiceAgentSipConnectionInput|VoiceAgentWebsocketConnectionInput, rotateSigningSecret?: true, voice?: string, language?: string, greeting?: string, instructions?: string, tools?: array{sendSms?: bool, transferTo?: ?string}}
 * @phpstan-type DeletedVoiceAgent array{id: string, object: string, deleted: bool}
 * @phpstan-type AgentVoice array{id: string, label: string, language: string}
 *
 * @see https://sendly.live/docs/voice
 */
class Voice
{
    public VoiceNumbers $numbers;
    public VoiceAgents $agents;
    public VoiceVoices $voices;

    public function __construct(Sendly $client)
    {
        $this->numbers = new VoiceNumbers($client);
        $this->agents = new VoiceAgents($client);
        $this->voices = new VoiceVoices($client);
    }

    /**
     * Get the voice numbers resource
     *
     * @return VoiceNumbers
     */
    public function numbers(): VoiceNumbers
    {
        return $this->numbers;
    }

    /**
     * Get the voice agents resource
     *
     * @return VoiceAgents
     */
    public function agents(): VoiceAgents
    {
        return $this->agents;
    }

    /**
     * Get the voices resource
     *
     * @return VoiceVoices
     */
    public function voices(): VoiceVoices
    {
        return $this->voices;
    }
}
