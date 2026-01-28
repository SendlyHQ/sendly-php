<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Generator;
use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

class Contacts
{
    private Sendly $client;
    private ContactLists $lists;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
        $this->lists = new ContactLists($client);
    }

    /**
     * Get the Contact Lists sub-resource
     *
     * @return ContactLists
     */
    public function lists(): ContactLists
    {
        return $this->lists;
    }

    /**
     * List contacts
     *
     * @param array{limit?: int, offset?: int, search?: string, listId?: string} $options Query options
     * @return array{contacts: array<array<string, mixed>>, total?: int, limit?: int, offset?: int}
     */
    public function list(array $options = []): array
    {
        $params = array_filter([
            'limit' => min($options['limit'] ?? 20, 100),
            'offset' => $options['offset'] ?? 0,
            'search' => $options['search'] ?? null,
            'list_id' => $options['listId'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->get('/contacts', $params);
    }

    /**
     * Get a contact by ID
     *
     * @param string $id Contact ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function get(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Contact ID is required');
        }

        return $this->client->get("/contacts/{$id}");
    }

    /**
     * Create a new contact
     *
     * @param string $phoneNumber Phone number in E.164 format
     * @param array{name?: string, email?: string, metadata?: array<string, mixed>} $options Additional options
     * @return array<string, mixed>
     * @throws ValidationException If phone number is empty
     */
    public function create(string $phoneNumber, array $options = []): array
    {
        if (empty($phoneNumber)) {
            throw new ValidationException('Phone number is required');
        }

        $data = array_merge([
            'phone_number' => $phoneNumber,
        ], array_filter([
            'name' => $options['name'] ?? null,
            'email' => $options['email'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ], fn($v) => $v !== null));

        return $this->client->post('/contacts', $data);
    }

    /**
     * Update a contact
     *
     * @param string $id Contact ID
     * @param array{phoneNumber?: string, name?: string, email?: string, metadata?: array<string, mixed>} $data Update data
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function update(string $id, array $data): array
    {
        if (empty($id)) {
            throw new ValidationException('Contact ID is required');
        }

        $body = array_filter([
            'phone_number' => $data['phoneNumber'] ?? null,
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ], fn($v) => $v !== null);

        return $this->client->patch("/contacts/{$id}", $body);
    }

    /**
     * Delete a contact
     *
     * @param string $id Contact ID
     * @return array{success: bool, message?: string}
     * @throws ValidationException If ID is empty
     */
    public function delete(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Contact ID is required');
        }

        return $this->client->delete("/contacts/{$id}");
    }

    /**
     * Iterate over all contacts with automatic pagination
     *
     * @param array{search?: string, listId?: string, batchSize?: int} $options Query options
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
                'search' => $options['search'] ?? null,
                'listId' => $options['listId'] ?? null,
            ]);

            $contacts = $response['contacts'] ?? $response['data'] ?? [];
            $hasMore = count($contacts) === $batchSize;

            foreach ($contacts as $contact) {
                yield $contact;
            }

            $offset += $batchSize;
        } while ($hasMore);
    }
}
