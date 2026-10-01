<?php

declare(strict_types=1);

namespace Sendly\Resources;

use Sendly\Sendly;
use Sendly\Exceptions\ValidationException;

class Rules
{
    private Sendly $client;

    public function __construct(Sendly $client)
    {
        $this->client = $client;
    }

    /**
     * List all rules
     *
     * @return array{data: array<array<string, mixed>>}
     */
    public function list(): array
    {
        return $this->client->get('/rules');
    }

    /**
     * Create a new rule
     *
     * @param string $name Rule name
     * @param array<string, mixed>|list<array<string, mixed>> $conditions Rule conditions, one object:
     *   `intent` and `sentiment` (a value or a list of values), `intentConfidenceMin` and
     *   `sentimentConfidenceMin`. A list of arrays is merged into one object.
     * @param array<string, mixed>|list<array<string, mixed>> $actions Rule actions, one object:
     *   `addLabels` (label IDs) and `closeConversation`. A list of arrays is merged into one object.
     * @param array{priority?: int} $options Additional options
     * @return array<string, mixed>
     * @throws ValidationException If parameters are invalid
     */
    public function create(string $name, array $conditions, array $actions, array $options = []): array
    {
        if (empty($name)) {
            throw new ValidationException('Rule name is required');
        }

        if (empty($conditions)) {
            throw new ValidationException('Rule conditions are required');
        }

        if (empty($actions)) {
            throw new ValidationException('Rule actions are required');
        }

        $payload = [
            'name' => $name,
            'conditions' => $this->asObject($conditions),
            'actions' => $this->asObject($actions),
        ];

        if (isset($options['priority'])) {
            $payload['priority'] = $options['priority'];
        }

        return $this->client->post('/rules', $payload);
    }

    /**
     * Update a rule
     *
     * @param string $id Rule ID
     * @param array{name?: string, conditions?: array<string, mixed>|list<array<string, mixed>>, actions?: array<string, mixed>|list<array<string, mixed>>, priority?: int} $data Update data,
     *   with `conditions` and `actions` as in create()
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function update(string $id, array $data): array
    {
        if (empty($id)) {
            throw new ValidationException('Rule ID is required');
        }

        foreach (['conditions', 'actions'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->asObject($data[$field]);
            }
        }

        return $this->client->patch("/rules/" . rawurlencode($id), $data);
    }

    /**
     * Delete a rule
     *
     * @param string $id Rule ID
     * @return array<string, mixed>
     * @throws ValidationException If ID is empty
     */
    public function delete(string $id): array
    {
        if (empty($id)) {
            throw new ValidationException('Rule ID is required');
        }

        return $this->client->delete("/rules/" . rawurlencode($id));
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function asObject(array $value): array
    {
        if ($value === [] || !array_is_list($value)) {
            return $value;
        }

        foreach ($value as $entry) {
            if (!is_array($entry) || $entry === [] || array_is_list($entry)) {
                return $value;
            }
        }

        return array_merge(...$value);
    }
}
