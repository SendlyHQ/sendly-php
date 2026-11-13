<?php

declare(strict_types=1);

namespace Sendly\Resources;

/**
 * A step of a connection test, as reported in `steps[].step` by
 * {@see VoiceAgents::test()}: `ADDRESS` (the host resolved to a public
 * address), `CONNECT` (a connection opened), `LOGIN` (the SIP server
 * accepted the credentials) and `REPLY` (the agent answered as it should).
 * More values may be added.
 */
final class VoiceAgentTestStepName
{
    public const ADDRESS = 'address';
    public const CONNECT = 'connect';
    public const LOGIN = 'login';
    public const REPLY = 'reply';
}
