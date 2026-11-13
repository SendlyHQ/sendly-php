<?php

declare(strict_types=1);

namespace Sendly\Resources;

/**
 * Who answers a call for an agent, as reported in `kind` on a voice agent
 * and in `agentKind` on a call, and accepted by {@see VoiceAgents::create()}
 * and {@see VoiceAgents::update()}.
 *
 * `SENDLY` has Sendly write the replies from the agent's instructions. The
 * others are your own agent: `CUSTOM_LLM` has Sendly listen and speak while
 * your chat completions endpoint writes each reply, `SIP` connects the caller
 * to your agent's SIP address, and `WEBSOCKET` streams the call audio to your
 * `wss://` server. More kinds may be added, so treat one you don't know as
 * your own agent rather than an error.
 */
final class VoiceAgentKind
{
    public const SENDLY = 'sendly';
    public const CUSTOM_LLM = 'custom_llm';
    public const SIP = 'sip';
    public const WEBSOCKET = 'websocket';
}
