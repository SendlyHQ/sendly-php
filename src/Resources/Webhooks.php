<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Webhook;
use Sendly\WebhookCreatedResponse;
use Sendly\WebhookDelivery;
use Sendly\WebhookTestResult;
use Sendly\WebhookSecretRotation;
use Sendly\Exceptions\ValidationException;

/**
 * Webhooks resource for managing webhook endpoints
 */
class Webhooks
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Create a new webhook
     *
     * @param string $url The URL to receive webhook events
     * @param array<string> $events List of event types to subscribe to
     * @param array{apiVersion?: string} $options Additional options
     * @return WebhookCreatedResponse The created webhook with secret
     * @throws ValidationException If parameters are invalid
     */
    public function create(string $url, array $events, array $options = []): WebhookCreatedResponse
    {
        if (empty($url)) {
            throw new ValidationException('Webhook URL is required');
        }

        if (empty($events)) {
            throw new ValidationException('At least one event type is required');
        }

        $payload = [
            'url' => $url,
            'events' => $events,
        ];

        if (isset($options['apiVersion'])) {
            $payload['apiVersion'] = $options['apiVersion'];
        }

        $response = $this->client->post('/webhooks', $payload);
        return new WebhookCreatedResponse($response);
    }

    /**
     * List all webhooks
     *
     * @return array<Webhook> List of webhooks
     */
    public function list(): array
    {
        $response = $this->client->get('/webhooks');
        $webhooks = $response['webhooks'] ?? $response['data'] ?? $response;

        if (!is_array($webhooks)) {
            return [];
        }

        return array_map(fn($data) => new Webhook($data), $webhooks);
    }

    /**
     * Get a webhook by ID
     *
     * @param string $id Webhook ID
     * @return Webhook The webhook
     * @throws ValidationException If ID is empty
     */
    public function get(string $id): Webhook
    {
        if (empty($id)) {
            throw new ValidationException('Webhook ID is required');
        }

        $response = $this->client->get("/webhooks/{$id}");
        $data = $response['webhook'] ?? $response['data'] ?? $response;
        return new Webhook($data);
    }

    /**
     * Update a webhook
     *
     * @param string $id Webhook ID
     * @param array{url?: string, events?: array<string>, isActive?: bool} $updates Updates to apply
     * @return Webhook The updated webhook
     * @throws ValidationException If ID is empty
     */
    public function update(string $id, array $updates): Webhook
    {
        if (empty($id)) {
            throw new ValidationException('Webhook ID is required');
        }

        // Convert SDK camelCase to API snake_case
        $payload = [];
        if (isset($updates['url'])) {
            $payload['url'] = $updates['url'];
        }
        if (isset($updates['events'])) {
            $payload['events'] = $updates['events'];
        }
        if (isset($updates['isActive'])) {
            $payload['is_active'] = $updates['isActive'];
        }

        $response = $this->client->patch("/webhooks/{$id}", $payload);
        $data = $response['webhook'] ?? $response['data'] ?? $response;
        return new Webhook($data);
    }

    /**
     * Delete a webhook
     *
     * @param string $id Webhook ID
     * @return bool True if deleted successfully
     * @throws ValidationException If ID is empty
     */
    public function delete(string $id): bool
    {
        if (empty($id)) {
            throw new ValidationException('Webhook ID is required');
        }

        $this->client->delete("/webhooks/{$id}");
        return true;
    }

    /**
     * Test a webhook endpoint
     *
     * @param string $id Webhook ID
     * @return WebhookTestResult The test result
     * @throws ValidationException If ID is empty
     */
    public function test(string $id): WebhookTestResult
    {
        if (empty($id)) {
            throw new ValidationException('Webhook ID is required');
        }

        $response = $this->client->post("/webhooks/{$id}/test");
        return new WebhookTestResult($response);
    }

    /**
     * Rotate a webhook's secret
     *
     * @param string $id Webhook ID
     * @return WebhookSecretRotation The new secret
     * @throws ValidationException If ID is empty
     */
    public function rotateSecret(string $id): WebhookSecretRotation
    {
        if (empty($id)) {
            throw new ValidationException('Webhook ID is required');
        }

        $response = $this->client->post("/webhooks/{$id}/rotate-secret");
        return new WebhookSecretRotation($response);
    }

    /**
     * List delivery attempts for a webhook
     *
     * @param string $id Webhook ID
     * @param array{limit?: int, offset?: int} $options Query options
     * @return array<WebhookDelivery> List of delivery attempts
     * @throws ValidationException If ID is empty
     */
    public function listDeliveries(string $id, array $options = []): array
    {
        if (empty($id)) {
            throw new ValidationException('Webhook ID is required');
        }

        $params = array_filter([
            'limit' => min($options['limit'] ?? 20, 100),
            'offset' => $options['offset'] ?? 0,
        ], fn($v) => $v !== null);

        $response = $this->client->get("/webhooks/{$id}/deliveries", $params);
        $deliveries = $response['deliveries'] ?? $response['data'] ?? $response;

        if (!is_array($deliveries)) {
            return [];
        }

        return array_map(fn($data) => new WebhookDelivery($data), $deliveries);
    }

    /**
     * Get a specific delivery attempt
     *
     * @param string $webhookId Webhook ID
     * @param string $deliveryId Delivery ID
     * @return WebhookDelivery The delivery attempt
     * @throws ValidationException If IDs are empty
     */
    public function getDelivery(string $webhookId, string $deliveryId): WebhookDelivery
    {
        if (empty($webhookId)) {
            throw new ValidationException('Webhook ID is required');
        }
        if (empty($deliveryId)) {
            throw new ValidationException('Delivery ID is required');
        }

        $response = $this->client->get("/webhooks/{$webhookId}/deliveries/{$deliveryId}");
        $data = $response['delivery'] ?? $response['data'] ?? $response;
        return new WebhookDelivery($data);
    }

    /**
     * Retry a failed delivery
     *
     * @param string $webhookId Webhook ID
     * @param string $deliveryId Delivery ID
     * @return WebhookDelivery The new delivery attempt
     * @throws ValidationException If IDs are empty
     */
    public function retryDelivery(string $webhookId, string $deliveryId): WebhookDelivery
    {
        if (empty($webhookId)) {
            throw new ValidationException('Webhook ID is required');
        }
        if (empty($deliveryId)) {
            throw new ValidationException('Delivery ID is required');
        }

        $response = $this->client->post("/webhooks/{$webhookId}/deliveries/{$deliveryId}/retry");
        $data = $response['delivery'] ?? $response['data'] ?? $response;
        return new WebhookDelivery($data);
    }
}
