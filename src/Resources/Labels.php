<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

/**
 * Labels resource for managing conversation labels
 */
class Labels
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * Create a new label
     *
     * @param string $name Label name
     * @param array{color?: string, description?: string} $options Additional options
     * @return array<string, mixed>
     * @throws ValidationException If name is empty
     */
    public function create(string $name, array $options = []): array
    {
        if (empty($name)) {
            throw new ValidationException('Label name is required');
        }

        $payload = ['name' => $name];

        if (isset($options['color'])) {
            $payload['color'] = $options['color'];
        }

        if (isset($options['description'])) {
            $payload['description'] = $options['description'];
        }

        return $this->client->post('/labels', $payload);
    }

    /**
     * List all labels
     *
     * @return array{data: array<array<string, mixed>>}
     */
    public function list(): array
    {
        return $this->client->get('/labels');
    }

    /**
     * Delete a label
     *
     * @param string $id Label ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function delete(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Label ID is required');
        }

        return $this->client->delete("/labels/{$id}");
    }
}
