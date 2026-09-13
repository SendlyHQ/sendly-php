<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

/**
 * Where a call is in its lifecycle, as reported in `status` on a call.
 *
 * `RINGING` and `ACTIVE` are live; everything else is terminal. `SUSPENDED`
 * can appear on an internal call whose media dropped and may recover.
 */
final class CallStatus
{
    public const RINGING = 'ringing';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const COMPLETED = 'completed';
    public const NO_ANSWER = 'no_answer';
    public const BUSY = 'busy';
    public const CANCELLED = 'cancelled';
    public const DECLINED = 'declined';
    public const FAILED = 'failed';
}

/**
 * Which way a call went, as reported in `direction`.
 */
final class CallDirection
{
    public const INBOUND = 'inbound';
    public const OUTBOUND = 'outbound';
}

/**
 * What kind of call it was, as reported in `kind`: a phone call, or a
 * browser-to-browser call between teammates.
 */
final class CallKind
{
    public const PSTN = 'pstn';
    public const INTERNAL = 'internal';
}

/**
 * Who answered the call, as reported in `handledBy`.
 */
final class CallHandledBy
{
    public const AGENT = 'agent';
    public const DASHBOARD = 'dashboard';
}

/**
 * The billing state of a call, as reported in `billing`. `METERED` while a
 * phone call is in progress and charged per minute, `SETTLED` once it has
 * ended and the charge is final, `UNBILLED` for a call that was never
 * charged (internal calls, and rows from before metering).
 */
final class CallBilling
{
    public const METERED = 'metered';
    public const SETTLED = 'settled';
    public const UNBILLED = 'unbilled';
}

/**
 * The state of a call's recording, as reported in `recordingStatus` on a
 * call (null when there is no recording) and in `status` on
 * {@see Calls::recording()} (`NONE` when there is no recording).
 */
final class CallRecordingStatus
{
    public const NONE = 'none';
    public const RECORDING = 'recording';
    public const READY = 'ready';
    public const FAILED = 'failed';
}

/**
 * Error codes the calls endpoints respond with. Read them from
 * {@see \Sendly\Exceptions\SendlyException::getApiErrorCode()}.
 */
final class CallErrorCode
{
    public const VOICE_NOT_ENABLED = 'voice_not_enabled';
    public const OUTBOUND_CALLS_NOT_ENABLED = 'outbound_calls_not_enabled';
    public const VOICE_UNAVAILABLE = 'voice_unavailable';
    public const AGENTS_UNAVAILABLE = 'agents_unavailable';
    public const AGENT_REQUIRED = 'agent_required';
    public const AGENT_NOT_FOUND = 'agent_not_found';
    public const AGENT_DISABLED = 'agent_disabled';
    public const INVALID_METADATA = 'invalid_metadata';
    public const INVALID_REQUEST = 'invalid_request';
    public const INVALID_NUMBER = 'invalid_number';
    public const FROM_NUMBER_REQUIRED = 'from_number_required';
    public const NO_VOICE_NUMBER = 'no_voice_number';
    public const NUMBER_NOT_FOUND = 'number_not_found';
    public const DESTINATION_NOT_SUPPORTED = 'destination_not_supported';
    public const E911_REQUIRED = 'e911_required';
    public const INSUFFICIENT_CREDITS = 'insufficient_credits';
    public const LINES_BUSY = 'lines_busy';
    public const DAILY_CALL_LIMIT = 'daily_call_limit';
    public const RATE_LIMIT_EXCEEDED = 'rate_limit_exceeded';
    public const CALL_NOT_FOUND = 'call_not_found';
    public const LIVE_KEY_REQUIRED = 'live_key_required';
    public const FORBIDDEN = 'forbidden';
    public const VOICE_INTERNAL_ERROR = 'voice_internal_error';
}

/**
 * Calls resource: phone calls handled by your AI agents.
 *
 * Place an outbound phone call that one of your workspace's AI agents
 * handles, list and inspect calls, end a call early, and fetch a call's
 * recording. Switching voice on for a number, choosing how it answers,
 * registering an emergency address and creating agents are done in the
 * dashboard; `$client->numbers()->list()` reports `voiceEnabled` and
 * `voiceMode` on each number so you can find one to call from.
 *
 * Calls are prepaid from your credit balance per started minute: 2 credits
 * a minute outbound plus 8 credits a minute while an AI agent is on the
 * call, so an agent-handled outbound call costs 10 credits a minute.
 * Unanswered calls cost nothing. Destinations are US and Canada.
 *
 * Voice is enabled workspace by workspace. Until it is enabled for your
 * account every endpoint here throws `NotFoundException`
 * (`voice_not_enabled`). Reads need an API key with the `calls:read`
 * scope, writes `calls:write`; writes also need a live key (a test key
 * gets 403 `live_key_required`).
 *
 * @phpstan-type CallTranscriptLine array{speaker: string, text: string, atMs: int}
 * @phpstan-type Call array{id: string, object: string, kind: string, direction: string, status: string, handledBy: string, agentId: ?string, from: ?string, to: ?string, callerName: ?string, calleeName: ?string, startedAt: string, answeredAt: ?string, endedAt: ?string, durationSecs: int, creditsCharged: int, billing: string, hangupClass: ?string, recordingStatus: ?string, metadata: array<string, string>, transcript?: array<int, CallTranscriptLine>}
 * @phpstan-type CallPagination array{total: int, limit: int, offset: int, hasMore: bool}
 * @phpstan-type CallRecording array{callId: string, status: string, url: ?string, expiresAt: ?string, contentType: ?string}
 *
 * @see https://sendly.live/docs/voice
 */
class Calls
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Place a phone call that one of your AI agents handles.
     *
     * The call is returned while it is still ringing (`status` `ringing`,
     * `handledBy` `agent`, `creditsCharged` 0). Poll {@see get()} or
     * subscribe to the `call.started` and `call.completed` webhooks to
     * follow it. `to` must be a US or Canadian number in E.164 format.
     * `from` is optional when exactly one of your numbers is voice-enabled
     * and required (400 `from_number_required`) when more are. `context` is
     * appended to the agent's instructions for this call only and is not
     * echoed back. `metadata` is stored and returned on every read and in
     * every `call.*` webhook: up to 20 keys of 1 to 40 characters matching
     * `^[A-Za-z0-9_.:-]+$`, with string values up to 500 characters.
     *
     * Refusals you should handle: 402 `insufficient_credits` (the balance
     * cannot cover one minute at the agent rate; `InsufficientCreditsException`),
     * 428 `e911_required` (register an emergency address for the number in
     * the dashboard first), 409 `lines_busy` (every line is in use; the
     * client throws at once rather than retrying, so try again in a moment)
     * and 429 `daily_call_limit`. See {@see CallErrorCode} for the full set.
     *
     * Requires a live API key with the `calls:write` scope. The POST
     * carries an `Idempotency-Key`; pass your own to make a retry from
     * another process return the call placed the first time instead of
     * dialling again.
     *
     * @param array{to: string, agentId: string, from?: string, context?: string, metadata?: array<string, string>} $params
     *   `to` and `agentId` are required.
     * @param string|null $idempotencyKey Idempotency key (1-255 printable
     *   ASCII characters). Overrides the auto-generated per-request key.
     * @return Call The new call, `status` `ringing`.
     * @throws ValidationException If `to` or `agentId` is empty.
     */
    public function create(array $params, ?string $idempotencyKey = null): array
    {
        if (empty($params['to'])) {
            throw new ValidationException('to is required');
        }
        if (empty($params['agentId'])) {
            throw new ValidationException('agentId is required');
        }

        $body = array_filter([
            'to' => $params['to'],
            'agentId' => $params['agentId'],
            'from' => $params['from'] ?? null,
            'context' => $params['context'] ?? null,
            'metadata' => !empty($params['metadata']) ? $params['metadata'] : null,
        ], fn($v) => $v !== null);

        return $this->client->post('/calls', $body, $idempotencyKey);
    }

    /**
     * List your workspace's calls, newest first.
     *
     * Live calls are reconciled before they are returned, so a ring past
     * its deadline reads as `no_answer`. `to` and `from` filter by exact
     * E.164 match. `pagination.hasMore` is true when a further page exists
     * at `offset + limit`.
     *
     * Requires an API key with the `calls:read` scope.
     *
     * @param array{limit?: int, offset?: int, status?: string, direction?: string, kind?: string, agentId?: string, to?: string, from?: string} $options
     *   `limit` is 1 to 100 (default 50), `offset` 0 or more (default 0);
     *   `status` is a {@see CallStatus}, `direction` a {@see CallDirection},
     *   `kind` a {@see CallKind}.
     * @return array{data: array<int, Call>, pagination: CallPagination}
     */
    public function list(array $options = []): array
    {
        $params = array_filter([
            'limit' => $options['limit'] ?? null,
            'offset' => $options['offset'] ?? null,
            'status' => $options['status'] ?? null,
            'direction' => $options['direction'] ?? null,
            'kind' => $options['kind'] ?? null,
            'agentId' => $options['agentId'] ?? null,
            'to' => $options['to'] ?? null,
            'from' => $options['from'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->get('/calls', $params);
    }

    /**
     * Fetch one call.
     *
     * Agent-handled calls also carry `transcript`, an array of
     * `{speaker, text, atMs}` lines where `speaker` is `caller` or `agent`
     * (empty when nothing was said). The key is absent on calls the team
     * answered. A call in another workspace throws `NotFoundException`
     * (`call_not_found`).
     *
     * Requires an API key with the `calls:read` scope.
     *
     * @param string $id Call ID
     * @return Call
     * @throws ValidationException If ID is empty.
     */
    public function get(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Call ID is required');
        }

        return $this->client->get('/calls/' . rawurlencode($id));
    }

    /**
     * End a call.
     *
     * A ringing call becomes `cancelled` (`hangupClass` `caller_cancelled`)
     * and the callee stops ringing; an active call becomes `completed`
     * (`hangupClass` `normal`). Hanging up a call that has already ended
     * returns it unchanged rather than failing.
     *
     * Requires a live API key with the `calls:write` scope.
     *
     * @param string $id Call ID
     * @param string|null $idempotencyKey Idempotency key (1-255 printable
     *   ASCII characters). Overrides the auto-generated per-request key.
     * @return Call The call after the hangup.
     * @throws ValidationException If ID is empty.
     */
    public function hangup(string $id, ?string $idempotencyKey = null): array
    {
        if (empty($id)) {
            throw new ValidationException('Call ID is required');
        }

        return $this->client->post('/calls/' . rawurlencode($id) . '/hangup', [], $idempotencyKey);
    }

    /**
     * Fetch a call's recording.
     *
     * `status` is a {@see CallRecordingStatus}: `none` when the call has no
     * recording (recording switched off, or the call was never answered),
     * `recording` while the call runs, `ready` or `failed` afterwards. `url`
     * and `expiresAt` are set only when `status` is `ready`; the URL is
     * signed and valid for five minutes, so fetch it when you need it
     * rather than storing it. Recordings are Ogg/Opus (`contentType`
     * `audio/ogg`); agent-handled calls are recorded dual-channel with the
     * caller on the left and the agent on the right.
     *
     * Requires an API key with the `calls:read` scope.
     *
     * @param string $id Call ID
     * @return CallRecording
     * @throws ValidationException If ID is empty.
     */
    public function recording(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Call ID is required');
        }

        return $this->client->get('/calls/' . rawurlencode($id) . '/recording');
    }
}
