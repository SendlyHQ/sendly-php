<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Generator;
use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

class ContactLists
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List contact lists
     *
     * @param array{limit?: int, offset?: int} $options Query options
     * @return array{lists: array<array<string, mixed>>, total?: int, limit?: int, offset?: int}
     */
    public function list(array $options = []): array
    {
        $params = array_filter([
            'limit' => min($options['limit'] ?? 20, 100),
            'offset' => $options['offset'] ?? 0,
        ], fn($v) => $v !== null);

        return $this->client->get('/contact-lists', $params);
    }

    /**
     * Get a contact list by ID
     *
     * @param string $id Contact list ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function get(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Contact list ID is required');
        }

        return $this->client->get("/contact-lists/{$id}");
    }

    /**
     * Create a new contact list
     *
     * @param string $name List name
     * @param string|null $description Optional description
     * @return array<string, mixed>
     * @throws ValidationException If name is empty
     */
    public function create(string $name, ?string $description = null): array
    {
        if (empty($name)) {
            throw new ValidationException('Contact list name is required');
        }

        $data = ['name' => $name];
        if ($description !== null) {
            $data['description'] = $description;
        }

        return $this->client->post('/contact-lists', $data);
    }

    /**
     * Update a contact list
     *
     * @param string $id Contact list ID
     * @param array{name?: string, description?: string} $data Update data
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function update(string $id, array $data): array
    {
        if (empty($id)) {
            throw new ValidationException('Contact list ID is required');
        }

        return $this->client->patch("/contact-lists/{$id}", $data);
    }

    /**
     * Delete a contact list
     *
     * @param string $id Contact list ID
     * @return array{success: bool, message?: string}
     * @throws ValidationException If ID is empty
     */
    public function delete(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Contact list ID is required');
        }

        return $this->client->delete("/contact-lists/{$id}");
    }

    /**
     * Add contacts to a list
     *
     * @param string $listId Contact list ID
     * @param array<string> $contactIds Array of contact IDs to add
     * @return array<string, mixed>
     * @throws ValidationException If parameters are invalid
     */
    public function addContacts(string $listId, array $contactIds): array
    {
        if (empty($listId)) {
            throw new ValidationException('Contact list ID is required');
        }

        if (empty($contactIds)) {
            throw new ValidationException('At least one contact ID is required');
        }

        return $this->client->post("/contact-lists/{$listId}/contacts", [
            'contact_ids' => $contactIds,
        ]);
    }

    /**
     * Remove a contact from a list
     *
     * @param string $listId Contact list ID
     * @param string $contactId Contact ID to remove
     * @return array{success: bool, message?: string}
     * @throws ValidationException If parameters are invalid
     */
    public function removeContact(string $listId, string $contactId): array
    {
        if (empty($listId)) {
            throw new ValidationException('Contact list ID is required');
        }

        if (empty($contactId)) {
            throw new ValidationException('Contact ID is required');
        }

        return $this->client->delete("/contact-lists/{$listId}/contacts/{$contactId}");
    }

    /**
     * Iterate over all contact lists with automatic pagination
     *
     * @param array{batchSize?: int} $options Query options
     * @return Generator<int, array<string, mixed>>
     */
    public function each(array $options = []): Generator
    {
        $batchSize = $options['batchSize'] ?? 100;
        $offset = 0;

        do {
            $response = $this->list([
                'limit' => $batchSize,
                'offset' => $offset,
            ]);

            $lists = $response['lists'] ?? $response['data'] ?? [];
            $hasMore = count($lists) === $batchSize;

            foreach ($lists as $list) {
                yield $list;
            }

            $offset += $batchSize;
        } while ($hasMore);
    }
}
