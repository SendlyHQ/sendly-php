<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;

/**
 * Where a short code stands, as reported in `status`. More values may be
 * added.
 */
final class ShortCodeStatus
{
    public const REQUESTED = 'requested';
    public const PROVISIONING = 'provisioning';
    /** Assigned but not cleared to send. */
    public const PARKED = 'parked';
    /** Sendable. */
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const CANCELLED = 'cancelled';
}

/**
 * Where Sendly's review of a short code application stands, as reported in
 * `reviewStatus`. More values may be added.
 */
final class ShortCodeReviewStatus
{
    public const DRAFT = 'draft';
    public const AWAITING_REVIEW = 'awaiting_review';
    public const CHANGES_REQUESTED = 'changes_requested';
    public const APPROVED_FOR_FILING = 'approved_for_filing';
    public const FILED = 'filed';
    public const REJECTED = 'rejected';
}

/**
 * The workspace's short code application.
 *
 * @phpstan-import-type ShortCodeApplicationInput from ShortCodes
 * @phpstan-import-type ShortCodeApplicationView from ShortCodes
 * @phpstan-import-type ShortCodePreflight from ShortCodes
 */
class ShortCodeApplication
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch the workspace's short code application, with the documents it
     * needs, the quote, and `billing`: where the setup fee and the monthly
     * lease stand.
     *
     * Requires the `short_codes:read` scope. While short codes aren't
     * enabled for the account this throws a NotFoundException
     * (`short_codes_not_enabled`).
     *
     * @return ShortCodeApplicationView The application, whether it is still
     *   editable, and why not when it isn't (`lockMessage`).
     */
    public function get(): array
    {
        /** @var ShortCodeApplicationView $view */
        $view = $this->client->get('/short_codes/application');
        return $view;
    }

    /**
     * Save answers on the application, creating it on the first call.
     *
     * Fields Sendly or the carriers own (the digits, the status, the review
     * state) are ignored and named back in `ignoredFields`. After a rejected
     * or cancelled application, the save starts a new one. It never submits.
     *
     * Requires the `short_codes:write` scope. Throws a SendlyException with
     * `short_code_locked` (409) once the application is with Sendly or the
     * carriers, or `short_code_conflict` (409) when another save changed it
     * first; a ValidationException (422) when `orderType` or `codeType` is
     * not a known value.
     *
     * @param ShortCodeApplicationInput $application The answers to save
     * @param string|null $idempotencyKey Idempotency key (1-255 printable
     *   ASCII characters), so a repeat of the same save within 24 hours
     *   returns the first answer.
     * @return ShortCodeApplicationView The saved application, with `ignoredFields`.
     */
    public function update(array $application, ?string $idempotencyKey = null): array
    {
        /** @var ShortCodeApplicationView $view */
        $view = $this->client->requestIdempotent('PUT', '/short_codes/application', $application, $idempotencyKey);
        return $view;
    }

    /**
     * Check the application against the carrier rules without changing
     * anything. Unsaved answers can be passed in and are checked on top of
     * what is saved. The carrier forms are listed but not checked: they are
     * signed after review.
     *
     * Requires the `short_codes:read` scope.
     *
     * @param ShortCodeApplicationInput $application Optional unsaved answers to check as well
     * @return ShortCodePreflight `ok`, every issue found, and the documents still outstanding.
     */
    public function check(array $application = []): array
    {
        /** @var ShortCodePreflight $preflight */
        $preflight = $this->client->post('/short_codes/application/preflight', $application);
        return $preflight;
    }

    /**
     * Submit the application to Sendly for review.
     *
     * Submitting charges the one-time $999 setup fee to the workspace's card
     * on file, so pass `acceptTerms => true` to accept it, the monthly lease
     * from go-live and the 3-month minimum. The fee is refunded in full if
     * Sendly rejects the application before filing it. Submitting twice is
     * safe: the second call reports `alreadySubmitted` and charges nothing.
     *
     * Requires the `short_codes:write` scope. Refusals: 403
     * `verification_required` until the workspace's business is verified
     * (the answers sent are saved anyway); 422
     * `short_code_invalid_application` with an `errors` array naming every
     * field, including `acceptTerms`, or `short_code_ai_draft_unedited` when
     * the only issues are wording Sendly suggested (`generatedDefaults`)
     * that nobody edited; 402 `payment_method_required`, `payment_failed`
     * or `payment_requires_authentication` (with `checkoutUrl`) when the fee
     * can't be charged, and the application stays a draft; 409
     * `short_code_locked`, `short_code_conflict`,
     * `short_code_payment_in_progress` or `short_code_refund_pending`.
     *
     * @param array<string, mixed> $application `acceptTerms`, plus optional
     *   final answers (any {@see ShortCodeApplicationInput} field), saved
     *   before the check.
     * @param string|null $idempotencyKey Idempotency key (1-255 printable
     *   ASCII characters), so a repeat of the same submit within 24 hours
     *   returns the first answer.
     * @return ShortCodeApplicationView The submitted application, with
     *   `submitted`, `alreadySubmitted`, `notified` and `payment` (`status`,
     *   and `charged`: whether this call charged the fee).
     */
    public function submit(array $application = [], ?string $idempotencyKey = null): array
    {
        /** @var ShortCodeApplicationView $view */
        $view = $this->client->post('/short_codes/application/submit', $application, $idempotencyKey);
        return $view;
    }
}

/**
 * Short codes resource: apply for a US short code and track the application.
 *
 * A short code is a 5 or 6 digit US sender. Getting one is an application,
 * not a purchase: you fill it in, Sendly reviews it, the carrier forms are
 * signed, Sendly files it, and each carrier certifies the code separately.
 *
 * The application lives at {@see ShortCodeApplication}; every application
 * and code is listed with {@see list()}.
 *
 * @phpstan-type ShortCodeLease array{monthlyCents: int, minimumTermMonths: int, startsAt?: string, state: string, billingEnabled: bool, startedAt: ?string, nextChargeAt: ?string, paidThrough: ?string, termEndsAt: ?string, endsAt: ?string, cancelRequestedAt: ?string, pastDue: ?array{chargeId: string, amountCents: int, status: string, periodStart: ?string, periodEnd: ?string, firstFailedAt: ?string, pauseAt: ?string, noticeStage: ?string}, dueMonths: int, dueCents: int}
 * @phpstan-type ShortCodeCharge array{id: string, kind: string, amountCents: int, status: string, paidAt: ?string, refundedCents: int, refundedAt: ?string, periodStart: ?string, periodEnd: ?string, createdAt: ?string}
 * @phpstan-type ShortCodeBilling array{setupFee: array{amountCents: int, status: string, chargedAt: string, paidAt: ?string, refundedCents: int, refundedAt: ?string}, lease: ShortCodeLease, terms: array{version: string, acceptedVersion: ?string, acceptedAt: ?string}, refundPolicy: string, exempt: ?string, charges: array<int, ShortCodeCharge>}
 * @phpstan-type ShortCode array{id: string, shortCode: ?string, countryCode: string, status: string, reviewStatus: string, useCase: string, createdAt: string, billing: array{setupFee: array{status: string, amountCents: int}, lease: ShortCodeLease}}
 * @phpstan-type ShortCodeDocument array{kind: string, templateReady: bool, signedAt: ?string, signedByName: ?string, awaitingSignature: bool}
 * @phpstan-type ShortCodeIssue array{path: string, message: string}
 * @phpstan-type ShortCodeApplicationRecord array{id: ?string, shortCode: ?string, countryCode: string, status: string, reviewStatus: string, reviewNote: ?string, orderType: string, codeType: string, contentProviderSameAsBrand: bool, sampleMessages: array<int, string>, useCase: ?string, optInFlow: ?string, optInConfirmation: ?string, helpResponse: ?string, stopConfirmation: ?string, messageFrequency: ?string, campaignKeyword: ?string, expectedMonthlyVolume: ?string, expectedDailyVolume: ?string, privacyPolicyUrl: ?string, termsUrl: ?string, requestedDigits: ?string, losingProvider: ?string, brandContactName: ?string, brandContactEmail: ?string, brandContactPhone: ?string, contentProviderLegalName: ?string, contentProviderEin: ?string, contentProviderContactName: ?string, contentProviderContactEmail: ?string, contentProviderContactPhone: ?string, brandRegistrationStatus: string, contentProviderRegistrationStatus: string, submittedAt: ?string, reviewedAt: ?string, filedAt: ?string, activatedAt: ?string, registryRevettingDueAt: ?string, createdAt: ?string, updatedAt: ?string}
 * @phpstan-type ShortCodeApplicationInput array{useCase?: ?string, optInFlow?: ?string, optInConfirmation?: ?string, helpResponse?: ?string, stopConfirmation?: ?string, messageFrequency?: ?string, campaignKeyword?: ?string, expectedMonthlyVolume?: ?string, expectedDailyVolume?: ?string, privacyPolicyUrl?: ?string, termsUrl?: ?string, countryCode?: string, orderType?: string, codeType?: string, requestedDigits?: ?string, losingProvider?: ?string, brandContactName?: ?string, brandContactEmail?: ?string, brandContactPhone?: ?string, contentProviderLegalName?: ?string, contentProviderEin?: ?string, contentProviderContactName?: ?string, contentProviderContactEmail?: ?string, contentProviderContactPhone?: ?string, contentProviderSameAsBrand?: bool, sampleMessages?: array<int, string>}
 * @phpstan-type ShortCodeApplicationView array{enabled: bool, application: ShortCodeApplicationRecord, prefill: array<string, mixed>, editable: bool, lockMessage: ?string, canStartNewApplication: bool, generatedDefaults: ?array{useCase?: string, optInFlow?: string}, requiredDocuments: array<int, string>, missingDocuments: array<int, string>, documents: array<int, ShortCodeDocument>, quote: array{codeType: string, monthlyUsd: int, setupUsd: int, currency: string, autoBilled: bool, setupCents: int, monthlyCents: int, minimumTermMonths: int, setupChargedAt: string, leaseStartsAt: string}, billing: ShortCodeBilling, verification: array{verified: bool, state: string, href: string}, carriers: array{approved: int, total: int, overall: string, byCarrier: array<int, array{carrier: string, label: string, status: string, updatedAt: ?string}>}, submitted?: bool, alreadySubmitted?: bool, notified?: bool, payment?: array{status: string, charged: bool}, ignoredFields?: array<int, string>}
 * @phpstan-type ShortCodePreflight array{ok: bool, issues: array<int, ShortCodeIssue>, requiredDocuments: array<int, string>, missingDocuments: array<int, string>, documents: array<int, ShortCodeDocument>, ignoredFields?: array<int, string>}
 */
class ShortCodes
{
    private Sendly $client;

    /** The workspace's short code application. */
    public ShortCodeApplication $application;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
        $this->application = new ShortCodeApplication($client);
    }

    /**
     * List every short code application of the workspace, newest first, with
     * its code once Sendly assigns one. Drafts (with `shortCode` null),
     * rejected and cancelled applications are listed too, so filter on
     * `status` ({@see ShortCodeStatus::ACTIVE}) for the codes you can send
     * from.
     *
     * Requires the `short_codes:read` scope.
     *
     * @return array{shortCodes: array<int, ShortCode>} Every application and
     *   code, each with `billing`: where its setup fee and lease stand.
     */
    public function list(): array
    {
        /** @var array{shortCodes: array<int, ShortCode>} $list */
        $list = $this->client->get('/short_codes');
        return $list;
    }
}
