<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Account as AccountModel;
use Sendly\Credits;
use Sendly\CreditTransaction;
use Sendly\ApiKey;
use Sendly\Exceptions\ValidationException;

/**
 * Account resource for managing account information and credits
 */
class Account
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Get current account information
     *
     * @return AccountModel The account information
     */
    public function get(): AccountModel
    {
        $response = $this->client->get('/account');
        $data = $response['account'] ?? $response['data'] ?? $response;
        return new AccountModel($data);
    }

    /**
     * Get current credit balance
     *
     * @return Credits The credit balance
     */
    public function credits(): Credits
    {
        $response = $this->client->get('/account/credits');
        $data = $response['credits'] ?? $response['data'] ?? $response;
        return new Credits($data);
    }

    /**
     * List credit transactions
     *
     * @param array{limit?: int, offset?: int, type?: string} $options Query options
     * @return array<CreditTransaction> List of transactions
     */
    public function transactions(array $options = []): array
    {
        $params = array_filter([
            'limit' => min($options['limit'] ?? 20, 100),
            'offset' => $options['offset'] ?? 0,
            'type' => $options['type'] ?? null,
        ], fn($v) => $v !== null);

        $response = $this->client->get('/account/transactions', $params);
        $transactions = $response['transactions'] ?? $response['data'] ?? $response;

        if (!is_array($transactions)) {
            return [];
        }

        return array_map(fn($data) => new CreditTransaction($data), $transactions);
    }

    /**
     * List API keys
     *
     * @return array<ApiKey> List of API keys
     */
    public function apiKeys(): array
    {
        $response = $this->client->get('/account/api-keys');
        $keys = $response['api_keys'] ?? $response['apiKeys'] ?? $response['data'] ?? $response;

        if (!is_array($keys)) {
            return [];
        }

        return array_map(fn($data) => new ApiKey($data), $keys);
    }

    /**
     * Create a new API key
     *
     * @param string $name Name for the API key
     * @param array{expiresAt?: string} $options Additional options
     * @return array{apiKey: ApiKey, key: string} The created API key with full key value
     */
    public function createApiKey(string $name, array $options = []): array
    {
        if (empty($name)) {
            throw new ValidationException('API key name is required');
        }

        $payload = ['name' => $name];
        if (isset($options['expiresAt'])) {
            $payload['expires_at'] = $options['expiresAt'];
        }

        $response = $this->client->post('/account/api-keys', $payload);

        return [
            'apiKey' => new ApiKey($response['api_key'] ?? $response['apiKey'] ?? $response),
            'key' => $response['key'] ?? '',
        ];
    }

    /**
     * Revoke an API key
     *
     * @param string $id API key ID
     * @return bool True if revoked successfully
     * @throws ValidationException If ID is empty
     */
    public function revokeApiKey(string $id): bool
    {
        if (empty($id)) {
            throw new ValidationException('API key ID is required');
        }

        $this->client->delete("/account/api-keys/{$id}");
        return true;
    }
}
