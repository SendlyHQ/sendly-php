<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

class EnterpriseWorkspaces
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * @param array{name: string, description?: string} $options
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function create(array $options): array
    {
        if (empty($options['name'])) {
            throw new ValidationException('Workspace name is required');
        }

        $payload = ['name' => $options['name']];
        if (isset($options['description'])) {
            $payload['description'] = $options['description'];
        }

        return $this->client->post('/enterprise/workspaces', $payload);
    }

    /**
     * @return array{workspaces: array<array<string, mixed>>, maxWorkspaces: int, workspacesUsed: int}
     */
    public function list(): array
    {
        return $this->client->get('/enterprise/workspaces');
    }

    /**
     * @param string $workspaceId
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function get(string $workspaceId): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        return $this->client->get("/enterprise/workspaces/{$workspaceId}");
    }

    /**
     * @param string $workspaceId
     * @return bool
     * @throws ValidationException
     */
    public function delete(string $workspaceId): bool
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        $this->client->delete("/enterprise/workspaces/{$workspaceId}");
        return true;
    }

    /**
     * @param string $workspaceId
     * @param array{businessName: string, businessType: string, ein: string, address: string, city: string, state: string, zip: string, useCase: string, sampleMessages: array<string>, monthlyVolume?: int} $data
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function submitVerification(string $workspaceId, array $data): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        $payload = [
            'business_name' => $data['businessName'],
            'business_type' => $data['businessType'],
            'ein' => $data['ein'],
            'address' => $data['address'],
            'city' => $data['city'],
            'state' => $data['state'],
            'zip' => $data['zip'],
            'use_case' => $data['useCase'],
            'sample_messages' => $data['sampleMessages'],
        ];

        if (isset($data['monthlyVolume'])) {
            $payload['monthly_volume'] = $data['monthlyVolume'];
        }

        return $this->client->post("/enterprise/workspaces/{$workspaceId}/verification/submit", $payload);
    }

    /**
     * @param string $workspaceId
     * @param array{sourceWorkspaceId: string} $options
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function inheritVerification(string $workspaceId, array $options): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        if (empty($options['sourceWorkspaceId'])) {
            throw new ValidationException('Source workspace ID is required');
        }

        return $this->client->post("/enterprise/workspaces/{$workspaceId}/verification/inherit", [
            'source_workspace_id' => $options['sourceWorkspaceId'],
        ]);
    }

    /**
     * @param string $workspaceId
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function getVerification(string $workspaceId): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        return $this->client->get("/enterprise/workspaces/{$workspaceId}/verification");
    }

    /**
     * @param string $workspaceId
     * @param array{sourceWorkspaceId: string, amount: int} $options
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function transferCredits(string $workspaceId, array $options): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        if (empty($options['sourceWorkspaceId'])) {
            throw new ValidationException('Source workspace ID is required');
        }

        if (!isset($options['amount']) || $options['amount'] <= 0) {
            throw new ValidationException('Amount must be a positive number');
        }

        return $this->client->post("/enterprise/workspaces/{$workspaceId}/transfer-credits", [
            'source_workspace_id' => $options['sourceWorkspaceId'],
            'amount' => $options['amount'],
        ]);
    }

    /**
     * @param string $workspaceId
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function getCredits(string $workspaceId): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        return $this->client->get("/enterprise/workspaces/{$workspaceId}/credits");
    }

    /**
     * @param string $workspaceId
     * @param array{name?: string, type?: string} $options
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function createKey(string $workspaceId, array $options = []): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        $payload = [];
        if (isset($options['name'])) {
            $payload['name'] = $options['name'];
        }
        if (isset($options['type'])) {
            $payload['type'] = $options['type'];
        }

        return $this->client->post("/enterprise/workspaces/{$workspaceId}/keys", $payload);
    }

    /**
     * @param string $workspaceId
     * @return array<array<string, mixed>>
     * @throws ValidationException
     */
    public function listKeys(string $workspaceId): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        $response = $this->client->get("/enterprise/workspaces/{$workspaceId}/keys");
        $keys = $response['keys'] ?? $response['data'] ?? $response;

        if (!is_array($keys)) {
            return [];
        }

        return $keys;
    }

    /**
     * @param string $workspaceId
     * @param string $keyId
     * @return bool
     * @throws ValidationException
     */
    public function revokeKey(string $workspaceId, string $keyId): bool
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        if (empty($keyId)) {
            throw new ValidationException('Key ID is required');
        }

        $this->client->delete("/enterprise/workspaces/{$workspaceId}/keys/{$keyId}");
        return true;
    }

    /**
     * @param string $workspaceId
     * @return array<array<string, mixed>>
     * @throws ValidationException
     */
    public function listOptInPages(string $workspaceId): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        return $this->client->get("/enterprise/workspaces/{$workspaceId}/opt-in-pages");
    }

    /**
     * @param string $workspaceId
     * @param array{businessName: string, useCase?: string, useCaseSummary?: string, sampleMessages?: array<string>} $options
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function createOptInPage(string $workspaceId, array $options): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        if (empty($options['businessName'])) {
            throw new ValidationException('Business name is required');
        }

        $payload = ['businessName' => $options['businessName']];
        if (isset($options['useCase'])) {
            $payload['useCase'] = $options['useCase'];
        }
        if (isset($options['useCaseSummary'])) {
            $payload['useCaseSummary'] = $options['useCaseSummary'];
        }
        if (isset($options['sampleMessages'])) {
            $payload['sampleMessages'] = $options['sampleMessages'];
        }

        return $this->client->post("/enterprise/workspaces/{$workspaceId}/opt-in-pages", $payload);
    }

    /**
     * @param string $workspaceId
     * @param string $pageId
     * @param array{logoUrl?: string, headerColor?: string, buttonColor?: string, customHeadline?: string, customBenefits?: array<string>} $options
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function updateOptInPage(string $workspaceId, string $pageId, array $options): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        if (empty($pageId)) {
            throw new ValidationException('Page ID is required');
        }

        $payload = [];
        if (isset($options['logoUrl'])) {
            $payload['logoUrl'] = $options['logoUrl'];
        }
        if (isset($options['headerColor'])) {
            $payload['headerColor'] = $options['headerColor'];
        }
        if (isset($options['buttonColor'])) {
            $payload['buttonColor'] = $options['buttonColor'];
        }
        if (isset($options['customHeadline'])) {
            $payload['customHeadline'] = $options['customHeadline'];
        }
        if (isset($options['customBenefits'])) {
            $payload['customBenefits'] = $options['customBenefits'];
        }

        return $this->client->patch("/enterprise/workspaces/{$workspaceId}/opt-in-pages/{$pageId}", $payload);
    }

    /**
     * @param string $workspaceId
     * @param string $pageId
     * @return bool
     * @throws ValidationException
     */
    public function deleteOptInPage(string $workspaceId, string $pageId): bool
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        if (empty($pageId)) {
            throw new ValidationException('Page ID is required');
        }

        $this->client->delete("/enterprise/workspaces/{$workspaceId}/opt-in-pages/{$pageId}");
        return true;
    }

    /**
     * @param string $workspaceId
     * @param array{url: string, events?: array<string>, description?: string} $options
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function setWebhook(string $workspaceId, array $options): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        if (empty($options['url'])) {
            throw new ValidationException('Webhook URL is required');
        }

        $payload = ['url' => $options['url']];
        if (isset($options['events'])) {
            $payload['events'] = $options['events'];
        }
        if (isset($options['description'])) {
            $payload['description'] = $options['description'];
        }

        return $this->client->put("/enterprise/workspaces/{$workspaceId}/webhooks", $payload);
    }

    /**
     * @param string $workspaceId
     * @return array<array<string, mixed>>
     * @throws ValidationException
     */
    public function listWebhooks(string $workspaceId): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        return $this->client->get("/enterprise/workspaces/{$workspaceId}/webhooks");
    }

    /**
     * @param string $workspaceId
     * @param string|null $webhookId
     * @return bool
     * @throws ValidationException
     */
    public function deleteWebhooks(string $workspaceId, ?string $webhookId = null): bool
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        $path = "/enterprise/workspaces/{$workspaceId}/webhooks";
        if ($webhookId !== null) {
            $path .= '?' . http_build_query(['webhookId' => $webhookId]);
        }

        $this->client->delete($path);
        return true;
    }

    /**
     * @param string $workspaceId
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function testWebhook(string $workspaceId): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        return $this->client->post("/enterprise/workspaces/{$workspaceId}/webhooks/test");
    }

    /**
     * @param string $workspaceId
     * @param string|null $reason
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function suspend(string $workspaceId, ?string $reason = null): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        $payload = [];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return $this->client->post("/enterprise/workspaces/{$workspaceId}/suspend", $payload);
    }

    /**
     * @param string $workspaceId
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function resume(string $workspaceId): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        return $this->client->post("/enterprise/workspaces/{$workspaceId}/resume");
    }

    /**
     * @param array<array<string, mixed>> $workspaces
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function provisionBulk(array $workspaces): array
    {
        if (empty($workspaces)) {
            throw new ValidationException('Workspaces array is required');
        }

        if (count($workspaces) > 50) {
            throw new ValidationException('Maximum 50 workspaces per bulk provision');
        }

        return $this->client->post('/enterprise/workspaces/provision/bulk', [
            'workspaces' => $workspaces,
        ]);
    }

    /**
     * @param string $workspaceId
     * @param string $pageId
     * @param string $domain
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function setCustomDomain(string $workspaceId, string $pageId, string $domain): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        if (empty($pageId)) {
            throw new ValidationException('Page ID is required');
        }

        if (empty($domain)) {
            throw new ValidationException('Domain is required');
        }

        return $this->client->put("/enterprise/workspaces/{$workspaceId}/pages/{$pageId}/domain", [
            'domain' => $domain,
        ]);
    }

    /**
     * @param string $workspaceId
     * @param array{email: string, role: string} $options
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function sendInvitation(string $workspaceId, array $options): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        if (empty($options['email'])) {
            throw new ValidationException('Email is required');
        }

        if (empty($options['role'])) {
            throw new ValidationException('Role is required');
        }

        return $this->client->post("/enterprise/workspaces/{$workspaceId}/invitations", [
            'email' => $options['email'],
            'role' => $options['role'],
        ]);
    }

    /**
     * @param string $workspaceId
     * @return array<array<string, mixed>>
     * @throws ValidationException
     */
    public function listInvitations(string $workspaceId): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        return $this->client->get("/enterprise/workspaces/{$workspaceId}/invitations");
    }

    /**
     * @param string $workspaceId
     * @param string $inviteId
     * @return bool
     * @throws ValidationException
     */
    public function cancelInvitation(string $workspaceId, string $inviteId): bool
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        if (empty($inviteId)) {
            throw new ValidationException('Invitation ID is required');
        }

        $this->client->delete("/enterprise/workspaces/{$workspaceId}/invitations/{$inviteId}");
        return true;
    }

    /**
     * @param string $workspaceId
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function getQuota(string $workspaceId): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        return $this->client->get("/enterprise/workspaces/{$workspaceId}/quota");
    }

    /**
     * @param string $workspaceId
     * @param int|null $monthlyMessageQuota
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function setQuota(string $workspaceId, ?int $monthlyMessageQuota): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        return $this->client->put("/enterprise/workspaces/{$workspaceId}/quota", [
            'monthlyMessageQuota' => $monthlyMessageQuota,
        ]);
    }
}

class EnterpriseWebhooks
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * @param array{url: string} $options
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function set(array $options): array
    {
        if (empty($options['url'])) {
            throw new ValidationException('Webhook URL is required');
        }

        return $this->client->put('/enterprise/webhooks', [
            'url' => $options['url'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->client->get('/enterprise/webhooks');
    }

    /**
     * @return bool
     */
    public function delete(): bool
    {
        $this->client->delete('/enterprise/webhooks');
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function test(): array
    {
        return $this->client->post('/enterprise/webhooks/test');
    }
}

class EnterpriseAnalytics
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return $this->client->get('/enterprise/analytics/overview');
    }

    /**
     * @param array{period?: string, workspaceId?: string} $options
     * @return array<string, mixed>
     */
    public function messages(array $options = []): array
    {
        $params = array_filter([
            'period' => $options['period'] ?? null,
            'workspaceId' => $options['workspaceId'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->get('/enterprise/analytics/messages', $params);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function delivery(): array
    {
        $response = $this->client->get('/enterprise/analytics/delivery');
        $items = $response['delivery'] ?? $response['data'] ?? $response;

        if (!is_array($items)) {
            return [];
        }

        return $items;
    }

    /**
     * @param array{period?: string} $options
     * @return array<string, mixed>
     */
    public function credits(array $options = []): array
    {
        $params = array_filter([
            'period' => $options['period'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->get('/enterprise/analytics/credits', $params);
    }
}

class EnterpriseSettings
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAutoTopUp(): array
    {
        return $this->client->get('/enterprise/settings/auto-top-up');
    }

    /**
     * @param array{enabled?: bool, threshold?: int, amount?: int, sourceWorkspaceId?: string} $options
     * @return array<string, mixed>
     */
    public function updateAutoTopUp(array $options): array
    {
        $payload = [];
        if (isset($options['enabled'])) {
            $payload['enabled'] = $options['enabled'];
        }
        if (isset($options['threshold'])) {
            $payload['threshold'] = $options['threshold'];
        }
        if (isset($options['amount'])) {
            $payload['amount'] = $options['amount'];
        }
        if (isset($options['sourceWorkspaceId'])) {
            $payload['sourceWorkspaceId'] = $options['sourceWorkspaceId'];
        }

        return $this->client->put('/enterprise/settings/auto-top-up', $payload);
    }
}

class EnterpriseBilling
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * @param array{period?: string, page?: int, limit?: int} $options
     * @return array<string, mixed>
     */
    public function getBreakdown(array $options = []): array
    {
        $params = array_filter([
            'period' => $options['period'] ?? null,
            'page' => isset($options['page']) ? (string) $options['page'] : null,
            'limit' => isset($options['limit']) ? (string) $options['limit'] : null,
        ], fn($v) => $v !== null);

        return $this->client->get('/enterprise/billing/workspace-breakdown', $params);
    }
}

class EnterpriseCredits
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->client->get('/enterprise/credits/pool');
    }

    /**
     * @param int $amount
     * @param string|null $description
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function deposit(int $amount, ?string $description = null): array
    {
        if ($amount <= 0) {
            throw new ValidationException('Amount must be a positive number');
        }

        $payload = ['amount' => $amount];
        if ($description !== null) {
            $payload['description'] = $description;
        }

        return $this->client->post('/enterprise/credits/pool/deposit', $payload);
    }
}

class Enterprise
{
    private Sendly $client;
    public EnterpriseWorkspaces $workspaces;
    public EnterpriseWebhooks $webhooks;
    public EnterpriseAnalytics $analytics;
    public EnterpriseSettings $settings;
    public EnterpriseBilling $billing;
    public EnterpriseCredits $credits;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
        $this->workspaces = new EnterpriseWorkspaces($client);
        $this->webhooks = new EnterpriseWebhooks($client);
        $this->analytics = new EnterpriseAnalytics($client);
        $this->settings = new EnterpriseSettings($client);
        $this->billing = new EnterpriseBilling($client);
        $this->credits = new EnterpriseCredits($client);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccount(): array
    {
        return $this->client->get('/enterprise/account');
    }

    /**
     * @param array{name: string, sourceWorkspaceId?: string, inheritWithNewNumber?: bool, verification?: array, creditAmount?: int, creditSourceWorkspaceId?: string, keyName?: string, keyType?: string, webhookUrl?: string, generateOptInPage?: bool} $options
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function provision(array $options): array
    {
        if (empty($options['name'])) {
            throw new ValidationException('Workspace name is required');
        }

        $payload = ['name' => $options['name']];

        if (isset($options['sourceWorkspaceId'])) {
            $payload['sourceWorkspaceId'] = $options['sourceWorkspaceId'];
        }
        if (!empty($options['inheritWithNewNumber'])) {
            $payload['inheritWithNewNumber'] = true;
        }
        if (isset($options['verification'])) {
            $payload['verification'] = $options['verification'];
        }
        if (isset($options['creditAmount'])) {
            $payload['creditAmount'] = $options['creditAmount'];
        }
        if (isset($options['creditSourceWorkspaceId'])) {
            $payload['creditSourceWorkspaceId'] = $options['creditSourceWorkspaceId'];
        }
        if (isset($options['keyName'])) {
            $payload['keyName'] = $options['keyName'];
        }
        if (isset($options['keyType'])) {
            $payload['keyType'] = $options['keyType'];
        }
        if (isset($options['webhookUrl'])) {
            $payload['webhookUrl'] = $options['webhookUrl'];
        }
        if (isset($options['generateOptInPage'])) {
            $payload['generateOptInPage'] = $options['generateOptInPage'];
        }

        return $this->client->post('/enterprise/workspaces/provision', $payload);
    }
}
