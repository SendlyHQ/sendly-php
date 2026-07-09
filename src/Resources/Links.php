<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

/**
 * Links resource — branded URL shortening.
 *
 * Mint branded short links for a destination URL, list the links your
 * workspace has created (with click analytics), and enable or disable an
 * individual link (a per-link kill switch).
 *
 * URL shortening is gated behind the `url_shortener` rollout flag; until the
 * flag is on for your account these endpoints read as absent — the API returns
 * `404 not_found`, surfaced as a {@see \Sendly\Exceptions\NotFoundException}.
 *
 * @see https://sendly.live/docs/links
 */
class Links
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Mint a branded short link for a destination URL. Uses your workspace's
     * brand slug when one is configured.
     *
     * @param string $url The destination URL to shorten (http/https only)
     * @return array{code: string, shortUrl: string, destinationUrl: string} The short link
     * @throws ValidationException If $url is empty or not an http/https URL
     */
    public function create(string $url): array
    {
        $this->validateUrl($url);

        return $this->client->post('/links', ['url' => $url]);
    }

    /**
     * List the short links your workspace has created, newest first, with
     * click counts and a 14-day daily click histogram.
     *
     * @param array{limit?: int, offset?: int} $options Pagination options
     *   (`limit` default 50, max 200; `offset` default 0)
     * @return array<string, mixed> The links and a total count
     */
    public function list(array $options = []): array
    {
        $params = array_filter([
            'limit' => $options['limit'] ?? null,
            'offset' => $options['offset'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->get('/links', $params);
    }

    /**
     * Enable or disable a short link (kill switch). A disabled link's redirect
     * returns 404 until it is re-enabled.
     *
     * @param string $code The short link code
     * @param bool $disabled True to disable, false to re-enable
     * @return array{code: string, disabled: bool} The link's code and new state
     * @throws ValidationException If $code is empty
     */
    public function update(string $code, bool $disabled): array
    {
        if (empty($code)) {
            throw new ValidationException('Link code is required');
        }

        return $this->client->patch(
            '/links/' . rawurlencode($code),
            ['disabled' => $disabled]
        );
    }

    /**
     * Disable a short link (its redirect returns 404 until re-enabled).
     * Convenience wrapper over {@see update()}.
     *
     * @param string $code The short link code
     * @return array{code: string, disabled: bool} The link's code and new state
     */
    public function disable(string $code): array
    {
        return $this->update($code, true);
    }

    /**
     * Re-enable a previously disabled short link. Convenience wrapper over
     * {@see update()}.
     *
     * @param string $code The short link code
     * @return array{code: string, disabled: bool} The link's code and new state
     */
    public function enable(string $code): array
    {
        return $this->update($code, false);
    }

    /**
     * Client-side guard mirroring the server's http/https-only check.
     *
     * @throws ValidationException
     */
    private function validateUrl(string $url): void
    {
        if ($url === '' || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
            throw new ValidationException('url must be an http:// or https:// URL');
        }
    }
}
