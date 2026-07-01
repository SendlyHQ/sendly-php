<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

/**
 * 10DLC resource — register your business for carrier review and text from
 * local (10-digit) US numbers.
 *
 * The flow has three steps:
 *
 *   1. Brand — register your business identity with {@see createBrand()}.
 *      Starts `pending`; poll {@see getBrand()} until it becomes `verified`
 *      (or `failed`, with `failureReasons` explaining why).
 *   2. Campaign — describe your messaging use case under a verified brand
 *      with {@see createCampaign()} and submit it for carrier review.
 *      Starts `pending`; poll {@see getCampaign()} until it becomes
 *      `active`. {@see qualify()} pre-checks a use case before you create
 *      the campaign.
 *   3. Assign — attach a number you own to the active campaign with
 *      {@see assignNumber()}. Once the assignment is `Active`, the number
 *      can send.
 *
 * Brand, campaign, and number-assignment writes require a live API key
 * (`sk_live_v1_xxx`) with the `tendlc:write` scope.
 *
 * @see https://sendly.live/docs/10dlc
 */
class TenDlc
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List the brands registered for carrier review.
     *
     * Brand `status` is `pending` (awaiting carrier review), `verified`
     * (approved; campaigns can be created under it), or `failed` (rejected;
     * see `failureReasons`).
     *
     * @return array{data: array<array{id: string, legalName: string, dba: ?string, entityType: string, ein: ?string, vertical: ?string, website: ?string, status: string, identityStatus: ?string, failureReasons: ?array<int, string>, createdAt: string, updatedAt: string}>}
     */
    public function listBrands(): array
    {
        return $this->client->get('/tendlc/brands');
    }

    /**
     * Register a brand for carrier review — step 1 of enabling local-number
     * texting. Requires a live API key.
     *
     * The brand starts `pending`. Poll {@see getBrand()} until it becomes
     * `verified` before creating a campaign.
     *
     * @param array{
     *   legalName: string,
     *   dba?: string,
     *   ein?: string,
     *   entityType?: string,
     *   vertical?: string,
     *   website?: string,
     *   email?: string,
     *   phone?: string,
     *   mobilePhone?: string,
     *   street?: string,
     *   city?: string,
     *   state?: string,
     *   postalCode?: string,
     *   country?: string,
     *   verificationId?: string
     * } $params Business identity details. `legalName` is required.
     *   `entityType` (e.g. `PRIVATE_PROFIT`, `SOLE_PROPRIETOR`) defaults to
     *   `PRIVATE_PROFIT`; `country` defaults to `US`. `verificationId`
     *   prefills business details from an existing Sendly verification.
     * @return array{data: array{id: string, legalName: string, dba: ?string, entityType: string, ein: ?string, vertical: ?string, website: ?string, status: string, identityStatus: ?string, failureReasons: ?array<int, string>, createdAt: string, updatedAt: string}}
     * @throws ValidationException If `legalName` is empty.
     */
    public function createBrand(array $params): array
    {
        if (empty($params['legalName'])) {
            throw new ValidationException('legalName is required');
        }

        $body = array_filter([
            'legalName' => $params['legalName'],
            'dba' => $params['dba'] ?? null,
            'ein' => $params['ein'] ?? null,
            'entityType' => $params['entityType'] ?? null,
            'vertical' => $params['vertical'] ?? null,
            'website' => $params['website'] ?? null,
            'email' => $params['email'] ?? null,
            'phone' => $params['phone'] ?? null,
            'mobilePhone' => $params['mobilePhone'] ?? null,
            'street' => $params['street'] ?? null,
            'city' => $params['city'] ?? null,
            'state' => $params['state'] ?? null,
            'postalCode' => $params['postalCode'] ?? null,
            'country' => $params['country'] ?? null,
            'verificationId' => $params['verificationId'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->post('/tendlc/brands', $body);
    }

    /**
     * Fetch one brand. Also refreshes its carrier-review status server-side,
     * so polling this method shows progress (`pending` → `verified`/`failed`).
     *
     * @param string $id Brand ID
     * @return array{data: array{id: string, legalName: string, dba: ?string, entityType: string, ein: ?string, vertical: ?string, website: ?string, status: string, identityStatus: ?string, failureReasons: ?array<int, string>, createdAt: string, updatedAt: string}}
     * @throws ValidationException If ID is empty.
     */
    public function getBrand(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Brand ID is required');
        }

        return $this->client->get("/tendlc/brands/{$id}");
    }

    /**
     * Pre-check whether a use case qualifies for a brand on the carrier
     * network before creating a campaign.
     *
     * @param string $brandId Brand ID
     * @param string $useCase Use-case code (e.g. `MIXED`, `MARKETING`,
     *   `ACCOUNT_NOTIFICATION`, `2FA`)
     * @return array{data: array{useCase: string, qualified: bool, reason: ?string, throughput: ?array{tier: string, carriersReady: int}}}
     *   `reason` is set only when `qualified` is false; `throughput` is the
     *   expected tier when the carrier network reports it.
     * @throws ValidationException If `brandId` or `useCase` is empty.
     */
    public function qualify(string $brandId, string $useCase): array
    {
        if (empty($brandId)) {
            throw new ValidationException('Brand ID is required');
        }
        if (empty($useCase)) {
            throw new ValidationException('Use case is required');
        }

        return $this->client->get("/tendlc/brands/{$brandId}/qualify/{$useCase}");
    }

    /**
     * List your messaging campaigns.
     *
     * Campaign `status` is `pending` (awaiting carrier review), `active`
     * (approved; numbers can be assigned), `failed` (rejected; see
     * `failureReasons`), `suspended` (paused by the carrier network), or
     * `expired` (no longer active).
     *
     * @return array{data: array<array{id: string, brandId: string, useCase: string, subUseCases: array<int, string>, description: ?string, status: string, sampleMessages: array<int, string>, throughput: ?array{tier: string, carriersReady: int}, failureReasons: ?array<int, string>, createdAt: string, updatedAt: string}>}
     */
    public function listCampaigns(): array
    {
        return $this->client->get('/tendlc/campaigns');
    }

    /**
     * Create a messaging campaign under a verified brand and submit it for
     * carrier review. Requires a live API key.
     *
     * The campaign starts `pending`. Poll {@see getCampaign()} until it
     * becomes `active` before assigning numbers.
     *
     * @param array{
     *   brandId: string,
     *   useCase: string,
     *   description: string,
     *   messageFlow: string,
     *   sampleMessages: array<int, string>,
     *   subUseCases?: array<int, string>,
     *   optInKeywords?: string,
     *   optOutKeywords?: string,
     *   helpKeywords?: string,
     *   optInMessage?: string,
     *   optOutMessage?: string,
     *   helpMessage?: string,
     *   embeddedLink?: bool,
     *   embeddedPhone?: bool
     * } $params Campaign details. `brandId` (a verified Sendly brand),
     *   `useCase`, `description`, `messageFlow` (how recipients opt in), and
     *   `sampleMessages` (the first 5 are used) are required. `embeddedLink`
     *   defaults to true; `embeddedPhone` defaults to false.
     * @return array{data: array{id: string, brandId: string, useCase: string, subUseCases: array<int, string>, description: ?string, status: string, sampleMessages: array<int, string>, throughput: ?array{tier: string, carriersReady: int}, failureReasons: ?array<int, string>, createdAt: string, updatedAt: string}}
     * @throws ValidationException If a required field is missing.
     */
    public function createCampaign(array $params): array
    {
        if (empty($params['brandId'])) {
            throw new ValidationException('brandId is required');
        }
        if (empty($params['useCase'])) {
            throw new ValidationException('useCase is required');
        }
        if (empty($params['description'])) {
            throw new ValidationException('description is required');
        }
        if (empty($params['messageFlow'])) {
            throw new ValidationException('messageFlow is required');
        }
        if (empty($params['sampleMessages'])) {
            throw new ValidationException('sampleMessages is required');
        }

        $body = array_filter([
            'brandId' => $params['brandId'],
            'useCase' => $params['useCase'],
            'description' => $params['description'],
            'messageFlow' => $params['messageFlow'],
            'sampleMessages' => $params['sampleMessages'],
            'subUseCases' => $params['subUseCases'] ?? null,
            'optInKeywords' => $params['optInKeywords'] ?? null,
            'optOutKeywords' => $params['optOutKeywords'] ?? null,
            'helpKeywords' => $params['helpKeywords'] ?? null,
            'optInMessage' => $params['optInMessage'] ?? null,
            'optOutMessage' => $params['optOutMessage'] ?? null,
            'helpMessage' => $params['helpMessage'] ?? null,
            'embeddedLink' => $params['embeddedLink'] ?? null,
            'embeddedPhone' => $params['embeddedPhone'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->post('/tendlc/campaigns', $body);
    }

    /**
     * Fetch one campaign. Also refreshes its carrier-review status
     * server-side, so polling this method shows progress (`pending` →
     * `active`) including throughput once carriers approve.
     *
     * @param string $id Campaign ID
     * @return array{data: array{id: string, brandId: string, useCase: string, subUseCases: array<int, string>, description: ?string, status: string, sampleMessages: array<int, string>, throughput: ?array{tier: string, carriersReady: int}, failureReasons: ?array<int, string>, createdAt: string, updatedAt: string}}
     * @throws ValidationException If ID is empty.
     */
    public function getCampaign(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Campaign ID is required');
        }

        return $this->client->get("/tendlc/campaigns/{$id}");
    }

    /**
     * Assign a phone number you own to an active (carrier-approved) campaign,
     * making the number sendable. Requires a live API key.
     *
     * Idempotent — re-assigning the same number to the same campaign returns
     * the existing assignment.
     *
     * Assignment `status` is `Active` (the number can send), `Under review`,
     * or `Action needed`; `assignedAt` is an ISO-8601 timestamp string, or
     * null while the assignment is in progress.
     *
     * @param string $campaignId Campaign ID
     * @param string $phoneNumber E.164 number the workspace already owns
     * @return array{data: array{id: string, campaignId: string, phoneNumber: string, status: string, assignedAt: ?string}}
     * @throws ValidationException If `campaignId` or `phoneNumber` is empty.
     */
    public function assignNumber(string $campaignId, string $phoneNumber): array
    {
        if (empty($campaignId)) {
            throw new ValidationException('Campaign ID is required');
        }
        if (empty($phoneNumber)) {
            throw new ValidationException('phoneNumber is required');
        }

        return $this->client->post("/tendlc/campaigns/{$campaignId}/assign", [
            'phoneNumber' => $phoneNumber,
        ]);
    }

    /**
     * List your number-to-campaign assignments.
     *
     * @return array{data: array<array{id: string, campaignId: string, phoneNumber: string, status: string, assignedAt: ?string}>}
     */
    public function listAssignments(): array
    {
        return $this->client->get('/tendlc/assignments');
    }
}
