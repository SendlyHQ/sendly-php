<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

class Templates
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List all templates (presets + custom)
     *
     * @return array{templates: array<array<string, mixed>>}
     */
    public function list(): array
    {
        return $this->client->get('/templates');
    }

    /**
     * List preset templates only
     *
     * @return array{templates: array<array<string, mixed>>}
     */
    public function presets(): array
    {
        return $this->client->get('/templates/presets');
    }

    /**
     * Get a template by ID
     *
     * @param string $id Template ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function get(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Template ID is required');
        }

        return $this->client->get("/templates/{$id}");
    }

    /**
     * Create a new template
     *
     * @param string $name Template name
     * @param string $text Template text with variables like {{code}} and {{app_name}}
     * @return array<string, mixed>
     * @throws ValidationException If parameters are invalid
     */
    public function create(string $name, string $text): array
    {
        if (empty($name)) {
            throw new ValidationException('Template name is required');
        }

        if (empty($text)) {
            throw new ValidationException('Template text is required');
        }

        return $this->client->post('/templates', [
            'name' => $name,
            'text' => $text,
        ]);
    }

    /**
     * Update a template
     *
     * @param string $id Template ID
     * @param array{name?: string, text?: string} $data Update data
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function update(string $id, array $data): array
    {
        if (empty($id)) {
            throw new ValidationException('Template ID is required');
        }

        return $this->client->patch("/templates/{$id}", $data);
    }

    /**
     * Publish a draft template
     *
     * @param string $id Template ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function publish(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Template ID is required');
        }

        return $this->client->post("/templates/{$id}/publish");
    }

    /**
     * Preview a template with sample values
     *
     * @param string $id Template ID
     * @param array<string, string> $variables Optional variable values
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function preview(string $id, array $variables = []): array
    {
        if (empty($id)) {
            throw new ValidationException('Template ID is required');
        }

        return $this->client->post("/templates/{$id}/preview", [
            'variables' => $variables,
        ]);
    }

    /**
     * Delete a template
     *
     * @param string $id Template ID
     * @return array{success: bool, message?: string}
     * @throws ValidationException If ID is empty
     */
    public function delete(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Template ID is required');
        }

        return $this->client->delete("/templates/{$id}");
    }

    /**
     * Clone a template
     *
     * @param string $id Template ID to clone
     * @param string|null $name Optional new name for the cloned template
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function clone(string $id, ?string $name = null): array
    {
        if (empty($id)) {
            throw new ValidationException('Template ID is required');
        }

        $data = [];
        if ($name !== null) {
            $data['name'] = $name;
        }

        return $this->client->post("/templates/{$id}/clone", $data);
    }
}
