<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

/**
 * Business Upgrade resource — Entity-Upgrade ("fork-with-new-number")
 *
 * Manages the toll-free business entity upgrade flow: when a customer
 * forms a new legal entity (e.g. an LLC), this resource lets them reserve
 * a new toll-free number under the new entity, submit it for carrier
 * review, and atomically swap to it on approval — without disrupting
 * outbound SMS during the 1-2 week review window.
 *
 * Field reference (all camelCase, matching the Node/Python/Ruby SDKs):
 *
 *   businessName, doingBusinessAs, brn, brnType, brnCountry, entityType,
 *   website, address1, address2, city, state, zip, addressCountry,
 *   contactFirstName, contactLastName, contactEmail, contactPhone,
 *   monthlyVolume, useCase, useCaseSummary, sampleMessages,
 *   optInWorkflow, privacyUrl, termsUrl, additionalInformation,
 *   ageGatedContent (bool).
 *
 *   brnType:     'EIN' | 'SSN' | 'DUNS' | 'CRA' | 'VAT' | 'LEI' | 'OTHER'
 *   entityType:  'SOLE_PROPRIETOR' | 'PRIVATE_PROFIT' | 'PUBLIC_PROFIT'
 *                | 'NON_PROFIT' | 'GOVERNMENT'
 *   disposition: 'moved' | 'released'
 *
 * @see https://sendly.live/docs/business-upgrade
 */
class BusinessUpgrade
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Validate a candidate entity-upgrade payload before submission.
     *
     * Returns issues + proposed auto-fixes. No writes — purely advisory.
     * Use this to preview which fields would block carrier review and
     * fix them before calling {@see start()}.
     *
     * @param array<string, mixed> $candidate Candidate payload — same
     *   shape as {@see start()}'s `$params`. Null values are filtered.
     * @return array{
     *   verificationId: string,
     *   businessName: ?string,
     *   country: string,
     *   verdict: string,
     *   issues: array<array<string, mixed>>,
     *   proposedFixes: array<array<string, mixed>>
     * }
     * @throws ValidationException If `businessName` is missing.
     */
    public function preflight(array $candidate): array
    {
        if (empty($candidate['businessName'])) {
            throw new ValidationException('businessName is required');
        }

        $body = array_filter($candidate, fn($v) => $v !== null);
        return $this->client->post('/verification/preflight', $body);
    }

    /**
     * Get a "best-of" prefill across all the caller's verified workspaces.
     *
     * Returns the most-recent non-empty value per messaging field. Use
     * this to pre-populate the upgrade form for users whose current
     * workspace has incomplete data.
     *
     * @return array{prefill: array<string, mixed>, sourceWorkspaceCount: int}
     */
    public function bestPrefill(): array
    {
        return $this->client->get('/verification/best-prefill');
    }

    /**
     * Start an entity upgrade for the given workspace.
     *
     * Auto-provisions a new toll-free number + messaging profile and
     * submits to the carrier for review. The current toll-free number
     * continues sending throughout the 1-2 week carrier review; on
     * approval, an atomic swap promotes the new number.
     *
     * Supply the IRS letter (CP-575 / 147C) via `$einDocPath` — it is
     * uploaded as multipart form field `einDoc` and stored on R2 until
     * the carrier review completes, then auto-deleted.
     *
     * @param string $workspaceId Workspace to upgrade.
     * @param array<string, mixed> $params Upgrade fields (see class docblock).
     * @param array{
     *   einDocPath?: string,
     *   einDocContents?: resource|string,
     *   einDocFilename?: string,
     *   einDocContentType?: string
     * } $options EIN-doc upload options. Either `einDocPath` (file path)
     *   or `einDocContents` (already-opened stream / raw bytes). When
     *   `einDocContents` is used, supply `einDocFilename` too.
     * @return array<string, mixed> Pending verification details, incl.
     *   `pendingVerificationId`, `tollFreeNumber`, `einDocStored`.
     * @throws ValidationException If `workspaceId` is empty, `businessName`
     *   is missing, or the supplied EIN doc file cannot be read.
     */
    public function start(string $workspaceId, array $params, array $options = []): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }
        if (empty($params['businessName'])) {
            throw new ValidationException('businessName is required');
        }

        $multipart = $this->buildMultipart($params, $options);
        $path = '/workspaces/' . rawurlencode($workspaceId) . '/upgrade';
        return $this->client->postMultipart($path, $multipart);
    }

    /**
     * Check whether the given workspace has a pending entity upgrade.
     *
     * @param string $workspaceId
     * @return array{pending: array<string, mixed>|null}
     * @throws ValidationException If `workspaceId` is empty.
     */
    public function status(string $workspaceId): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        $path = '/workspaces/' . rawurlencode($workspaceId) . '/upgrade/status';
        return $this->client->get($path);
    }

    /**
     * Cancel a pending entity upgrade for the given workspace.
     *
     * Releases the reserved toll-free number, deletes the new messaging
     * profile, and removes the stored EIN document. Idempotent — calling
     * on a workspace without a pending upgrade returns `cancelled: false`.
     *
     * @param string $workspaceId
     * @return array{
     *   success: bool,
     *   cancelled: bool,
     *   cancelledVerificationId?: string,
     *   message: string
     * }
     * @throws ValidationException If `workspaceId` is empty.
     */
    public function cancel(string $workspaceId): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        $path = '/workspaces/' . rawurlencode($workspaceId) . '/upgrade/cancel';
        return $this->client->post($path);
    }

    /**
     * Resubmit a rejected (or waiting-for-customer) entity upgrade with
     * updated fields and optionally a new EIN document.
     *
     * Partial-update friendly: send only the fields you want to change.
     * The EIN doc is optional on resubmit — omit `$options` to keep the
     * previously uploaded copy.
     *
     * @param string $workspaceId
     * @param array<string, mixed> $params Fields to update (camelCase).
     * @param array{
     *   einDocPath?: string,
     *   einDocContents?: resource|string,
     *   einDocFilename?: string,
     *   einDocContentType?: string
     * } $options EIN-doc upload options (same shape as {@see start()}).
     * @return array{success: bool, pendingVerificationId: string, message: string}
     * @throws ValidationException If `workspaceId` is empty.
     */
    public function resubmit(string $workspaceId, array $params = [], array $options = []): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }

        $multipart = $this->buildMultipart($params, $options);
        $path = '/workspaces/' . rawurlencode($workspaceId) . '/upgrade/resubmit';
        return $this->client->postMultipart($path, $multipart);
    }

    /**
     * After a successful entity-upgrade approval, choose what happens to
     * the old toll-free number:
     *
     *   - `moved`:    keep it active under another workspace owned by
     *                 the same user. Requires `targetWorkspaceId`.
     *   - `released`: return it to the carrier pool.
     *
     * @param string $workspaceId
     * @param array{disposition: string, targetWorkspaceId?: string} $body
     * @return array{
     *   success: bool,
     *   disposition: string,
     *   supersededVerificationId: string,
     *   message: string
     * }
     * @throws ValidationException If `workspaceId` or `disposition` is empty,
     *   or if `disposition` is `'moved'` without `targetWorkspaceId`.
     */
    public function setDisposition(string $workspaceId, array $body): array
    {
        if (empty($workspaceId)) {
            throw new ValidationException('Workspace ID is required');
        }
        if (empty($body['disposition'])) {
            throw new ValidationException('disposition is required');
        }
        if ($body['disposition'] === 'moved' && empty($body['targetWorkspaceId'])) {
            throw new ValidationException("targetWorkspaceId is required when disposition is 'moved'");
        }

        $payload = ['disposition' => $body['disposition']];
        if (!empty($body['targetWorkspaceId'])) {
            // Server contract still uses targetOrgId on the wire.
            $payload['targetOrgId'] = $body['targetWorkspaceId'];
        }

        $path = '/workspaces/' . rawurlencode($workspaceId) . '/upgrade/disposition';
        return $this->client->post($path, $payload);
    }

    /**
     * Build the Guzzle multipart payload for start/resubmit.
     *
     * - Scalar/boolean params are appended as form fields (bools stringified).
     * - Array/object params are JSON-encoded so the server can decode them
     *   back to structured values.
     * - Null params are skipped.
     * - The EIN doc, if provided, is appended as field `einDoc`.
     *
     * @param array<string, mixed> $params
     * @param array{
     *   einDocPath?: string,
     *   einDocContents?: resource|string,
     *   einDocFilename?: string,
     *   einDocContentType?: string
     * } $options
     * @return array<int, array<string, mixed>>
     * @throws ValidationException If the EIN-doc file path is unreadable.
     */
    private function buildMultipart(array $params, array $options): array
    {
        $multipart = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_bool($value)) {
                $contents = $value ? 'true' : 'false';
            } elseif (is_array($value) || is_object($value)) {
                $contents = json_encode($value);
                if ($contents === false) {
                    $contents = '';
                }
            } else {
                $contents = (string) $value;
            }

            $multipart[] = [
                'name' => $key,
                'contents' => $contents,
            ];
        }

        $einDocPart = $this->buildEinDocPart($options);
        if ($einDocPart !== null) {
            $multipart[] = $einDocPart;
        }

        return $multipart;
    }

    /**
     * @param array{
     *   einDocPath?: string,
     *   einDocContents?: resource|string,
     *   einDocFilename?: string,
     *   einDocContentType?: string
     * } $options
     * @return array<string, mixed>|null
     * @throws ValidationException
     */
    private function buildEinDocPart(array $options): ?array
    {
        $hasPath = isset($options['einDocPath']) && $options['einDocPath'] !== '';
        $hasContents = isset($options['einDocContents']) && $options['einDocContents'] !== '';

        if (!$hasPath && !$hasContents) {
            return null;
        }

        $contentType = $options['einDocContentType'] ?? 'application/pdf';

        if ($hasPath) {
            $path = $options['einDocPath'];
            if (!file_exists($path)) {
                throw new ValidationException("EIN doc file not found: {$path}");
            }
            if (!is_readable($path)) {
                throw new ValidationException("EIN doc file is not readable: {$path}");
            }

            return [
                'name' => 'einDoc',
                'contents' => fopen($path, 'r'),
                'filename' => $options['einDocFilename'] ?? basename($path),
                'headers' => ['Content-Type' => $contentType],
            ];
        }

        return [
            'name' => 'einDoc',
            'contents' => $options['einDocContents'],
            'filename' => $options['einDocFilename'] ?? 'ein-doc.pdf',
            'headers' => ['Content-Type' => $contentType],
        ];
    }
}
