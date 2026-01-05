<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;

class Templates
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List verification templates
     *
     * @param array{limit?: int, type?: string, locale?: string} $options Query options
     * @return array{templates: array<array<string, mixed>>, pagination?: array<string, mixed>}
     */
    public function list(array $options = []): array
    {
        return $this->client->get('/verify/templates', $options);
    }

    /**
     * Get a template by ID
     *
     * @param string $id Template ID
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->client->get("/verify/templates/{$id}");
    }

    /**
     * Create a new template
     *
     * @param string $name Template name
     * @param string $body Template body with {{code}} and {{appName}} variables
     * @param array{locale?: string, isPublished?: bool} $options Additional options
     * @return array<string, mixed>
     */
    public function create(string $name, string $body, array $options = []): array
    {
        $data = array_merge([
            'name' => $name,
            'body' => $body,
        ], $options);

        return $this->client->post('/verify/templates', $data);
    }

    /**
     * Update a template
     *
     * @param string $id Template ID
     * @param array{name?: string, body?: string, locale?: string, isPublished?: bool} $data Update data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        return $this->client->patch("/verify/templates/{$id}", $data);
    }

    /**
     * Delete a template
     *
     * @param string $id Template ID
     * @return array{success: bool, message?: string}
     */
    public function delete(string $id): array
    {
        return $this->client->delete("/verify/templates/{$id}");
    }

    /**
     * Publish a template
     *
     * @param string $id Template ID
     * @return array<string, mixed>
     */
    public function publish(string $id): array
    {
        return $this->client->post("/verify/templates/{$id}/publish");
    }

    /**
     * Unpublish a template
     *
     * @param string $id Template ID
     * @return array<string, mixed>
     */
    public function unpublish(string $id): array
    {
        return $this->client->post("/verify/templates/{$id}/unpublish");
    }
}
