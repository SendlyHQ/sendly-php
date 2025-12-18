<?php

declare(strict_types=1);

namespace Sendly;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Represents a paginated list of messages
 *
 * @implements IteratorAggregate<int, Message>
 */
class MessageList implements IteratorAggregate, Countable
{
    /** @var array<int, Message> */
    private array $messages;

    public readonly int $total;
    public readonly int $limit;
    public readonly int $offset;
    public readonly bool $hasMore;

    /**
     * Create a MessageList from API response data
     *
     * @param array<string, mixed> $response Response data
     */
    public function __construct(array $response)
    {
        $data = $response['data'] ?? [];
        $this->messages = array_map(
            fn(array $item) => new Message($item),
            $data
        );

        $pagination = $response['pagination'] ?? [];
        $this->total = (int) ($pagination['total'] ?? count($this->messages));
        $this->limit = (int) ($pagination['limit'] ?? 20);
        $this->offset = (int) ($pagination['offset'] ?? 0);
        $this->hasMore = (bool) ($pagination['has_more'] ?? false);
    }

    /**
     * Get all messages
     *
     * @return array<int, Message>
     */
    public function all(): array
    {
        return $this->messages;
    }

    /**
     * Get first message
     */
    public function first(): ?Message
    {
        return $this->messages[0] ?? null;
    }

    /**
     * Get last message
     */
    public function last(): ?Message
    {
        $count = count($this->messages);
        return $count > 0 ? $this->messages[$count - 1] : null;
    }

    /**
     * Check if empty
     */
    public function isEmpty(): bool
    {
        return empty($this->messages);
    }

    /**
     * Get message count
     */
    public function count(): int
    {
        return count($this->messages);
    }

    /**
     * Get iterator
     *
     * @return Traversable<int, Message>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->messages);
    }

    /**
     * Convert to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(fn(Message $m) => $m->toArray(), $this->messages),
            'pagination' => [
                'total' => $this->total,
                'limit' => $this->limit,
                'offset' => $this->offset,
                'has_more' => $this->hasMore,
            ],
        ];
    }
}
