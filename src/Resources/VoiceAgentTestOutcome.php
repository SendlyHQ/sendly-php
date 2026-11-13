<?php

declare(strict_types=1);

namespace Sendly\Resources;

/**
 * What a connection test found, as reported in `outcome` by
 * {@see VoiceAgents::test()}.
 *
 * `OK` means the agent answered the way a call needs. Otherwise:
 * `BLOCKED_DESTINATION` (the host isn't a public address), `UNREACHABLE`,
 * `TIMEOUT`, `TLS_FAILED`, `AUTH_REQUIRED` (the SIP server wants
 * credentials the agent doesn't have), `AUTH_FAILED` (the key, token or
 * credentials were refused), `NOT_FOUND` (wrong path or SIP user),
 * `REJECTED` (any other refusal) or `BAD_REPLY` (an answer outside the
 * protocol). More values may be added.
 */
final class VoiceAgentTestOutcome
{
    public const OK = 'ok';
    public const BLOCKED_DESTINATION = 'blocked_destination';
    public const UNREACHABLE = 'unreachable';
    public const TIMEOUT = 'timeout';
    public const TLS_FAILED = 'tls_failed';
    public const AUTH_REQUIRED = 'auth_required';
    public const AUTH_FAILED = 'auth_failed';
    public const NOT_FOUND = 'not_found';
    public const REJECTED = 'rejected';
    public const BAD_REPLY = 'bad_reply';
}
