<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

class RcsAgents
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List your RCS agents.
     *
     * Returns the RCS agents registered for your workspace, newest first.
     * An empty list means no agent is set up yet — contact support to
     * register an RCS agent for your brand.
     *
     * Agent `status` is `draft`, `submitted`, `testing` (can send, but only
     * to invited test devices), `approved` (can send to everyone), or
     * `suspended`. `sendable` is true when the agent is fully provisioned
     * and its status allows sending — only sendable agents can be used with
     * `messages()->send(['channel' => 'rcs', ...])`.
     *
     * @return array{agents: array<array{id: string, name: string, status: string, useCase: ?string, sendable: bool, createdAt: string}>}
     */
    public function list(): array
    {
        return $this->client->get('/rcs/agents');
    }
}

/**
 * RCS resource — discover your RCS agents and pre-flight recipient
 * capability.
 *
 * RCS is a first-class Sendly channel: send branded rich messages — text
 * with suggested replies and actions, or rich cards — via
 * `$client->messages()->send(['channel' => 'rcs', ...])`. RCS agents are
 * registered by Sendly for your brand; contact support to set one up, then
 * discover it with {@see RcsAgents::list()}.
 *
 * Delivery is per-recipient: not every device or network supports RCS.
 * Text messages fall back to plain SMS automatically (billed as SMS)
 * unless you pass `'fallbackToSms' => false`; rich cards have no SMS form
 * and only deliver to RCS-capable recipients. Use {@see capability()} to
 * check a recipient before sending.
 *
 * RCS sends and capability checks require a live API key
 * (`sk_live_v1_xxx`).
 */
class Rcs
{
    private Sendly $client;
    public RcsAgents $agents;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
        $this->agents = new RcsAgents($client);
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
