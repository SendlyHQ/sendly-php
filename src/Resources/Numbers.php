<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

/**
 * Numbers resource — search, buy, and list phone numbers.
 *
 * Browse the countries Sendly can provision numbers in, search live
 * availability with already-customer-priced monthly costs, list the numbers
 * on your account, and buy a number.
 *
 * Buying is asynchronous. {@see buy()} returns a `status` of:
 *
 *   - `provisioning`        — the number is being set up; poll {@see list()}
 *                             until it reports the number as active.
 *   - `documents_required`  — regulatory documents are needed before the
 *                             number can be provisioned.
 *   - `payment_required`    — a payment method must be added first.
 *
 * For `documents_required` and `payment_required`, the response carries an
 * `action` object ({ url, code, actionCode, expiresAt }). The two identifiers
 * are different and used for different things:
 *
 *   - `actionCode` — a 32-hex action identifier. Use THIS to poll the action's
 *                    status and to re-buy (pass it as the `actionCode` field on
 *                    the next {@see buy()} call). Never use `code` here.
 *   - `code`       — a short, human-typeable user code. DISPLAY only: show it to
 *                    the user to type on the hosted page to prove terminal
 *                    access. It is not the re-buy identifier.
 *
 * Hand the user `action.url` (a hosted Sendly page) along with `action.code`,
 * wait for them to finish, then re-call {@see buy()} with the SAME body plus
 * `actionCode` set to `action.actionCode` of the completed action. (Polling the
 * action's completion is the caller's job — the SDK only exposes the
 * endpoints.) `action.expiresAt` is an epoch-milliseconds integer.
 *
 * @see https://sendly.live/docs/numbers
 */
class Numbers
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List the countries Sendly can provision numbers in, with the number
     * types available in each.
     *
     * @return array{countries: array<array{code: string, name: string, numberTypes: array<int, string>}>}
     */
    public function listCountries(): array
    {
        return $this->client->get('/numbers/countries');
    }

    /**
     * Search live number availability for a country and type. Monthly costs
     * are returned already customer-priced.
     *
     * @param array{country: string, type: string, contains?: string} $options
     *   `country` is an ISO country code (e.g. `GB`); `type` is a number type
     *   (e.g. `mobile`, `local`, `toll_free`); optional `contains` filters by
     *   a digit pattern the number must contain.
     * @return array{numbers: array<array{phoneNumber: string, country: string, numberType: string, monthlyCost: string, currency: string}>}
     * @throws ValidationException If `country` or `type` is empty.
     */
    public function listAvailable(array $options): array
    {
        if (empty($options['country'])) {
            throw new ValidationException('country is required');
        }
        if (empty($options['type'])) {
            throw new ValidationException('type is required');
        }

        $params = array_filter([
            'country' => $options['country'],
            'type' => $options['type'],
            'contains' => $options['contains'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->get('/numbers/available', $params);
    }

    /**
     * List the phone numbers on your account.
     *
     * `requirementsSubmittedAt` is an ISO-8601 timestamp string, or null when
     * the number still needs regulatory documents; a value means documents
     * were submitted and are under carrier review. `pendingCancellation` is
     * true when the number is scheduled for release at period end, and
     * `scheduledReleaseAt` is the ISO-8601 timestamp string of that release
     * (null when not scheduled).
     *
     * @return array{numbers: array<array{id: string, phoneNumber: string, status: string, source: string, countryCode: string, phoneNumberType: string, monthlyCostCents: int, requirementsSubmittedAt: ?string, pendingCancellation: bool, scheduledReleaseAt: ?string}>}
     */
    public function list(): array
    {
        return $this->client->get('/numbers');
    }

    /**
     * Buy a phone number.
     *
     * Provisioning is asynchronous — see the class docblock for the `status`
     * values and the `documents_required` / `payment_required` action
     * hand-off. On those statuses, re-call this method with the same body plus
     * `actionCode` set to `action.actionCode` (the 32-hex identifier) of the
     * completed action — NOT the human-facing `action.code`.
     *
     * @param array{
     *   phoneNumber: string,
     *   countryCode: string,
     *   phoneNumberType: string,
     *   monthlyCost: string,
     *   actionCode?: string
     * } $params The number to buy (from {@see listAvailable()}). `monthlyCost`
     *   is the already-customer-priced cost returned by availability search.
     *   `actionCode` is the 32-hex `action.actionCode` from a prior pending
     *   buy response, set when re-buying after the action is completed.
     * @return array{
     *   status: string,
     *   number?: array{id: string, phoneNumber: string, status: string},
     *   requirements?: array<int, mixed>,
     *   action?: array{url: string, code: string, actionCode: string, expiresAt: int}
     * } The parsed buy response (decoded verbatim). `action` is present only when
     *   `status` is `documents_required` or `payment_required`; it carries both
     *   `actionCode` (32-hex id, for polling + re-buy) and `code` (display-only
     *   user code), and `expiresAt` is epoch milliseconds. On success `number`
     *   contains only `id`, `phoneNumber`, and `status`.
     * @throws ValidationException If a required field is missing.
     */
    public function buy(array $params): array
    {
        if (empty($params['phoneNumber'])) {
            throw new ValidationException('phoneNumber is required');
        }
        if (empty($params['countryCode'])) {
            throw new ValidationException('countryCode is required');
        }
        if (empty($params['phoneNumberType'])) {
            throw new ValidationException('phoneNumberType is required');
        }
        if (empty($params['monthlyCost'])) {
            throw new ValidationException('monthlyCost is required');
        }

        $body = array_filter([
            'phoneNumber' => $params['phoneNumber'],
            'countryCode' => $params['countryCode'],
            'phoneNumberType' => $params['phoneNumberType'],
            'monthlyCost' => $params['monthlyCost'],
            'actionCode' => $params['actionCode'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->post('/numbers/buy', $body);
    }
}
