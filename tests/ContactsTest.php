<?php

declare(strict_types=1);

namespace Sendly\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;
use Sendly\Sendly;

/**
 * Tests for the Contacts resource and its contact lists
 */
class ContactsTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface, response: ?Response}> */
    private array $history = [];

    /**
     * @param array<int, Response> $responses
     */
    private function createMockClient(array $responses): Sendly
    {
        $this->history = [];
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($this->history));
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new Sendly('test_api_key');

        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('httpClient');
        $property->setValue($client, $httpClient);

        return $client;
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(int $index): array
    {
        parse_str($this->history[$index]['request']->getUri()->getQuery(), $query);
        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function wireContact(int $n): array
    {
        return [
            'id' => 'cnt_' . $n,
            'phone_number' => sprintf('+1555555%04d', $n),
            'name' => 'Contact ' . $n,
            'email' => null,
            'metadata' => [],
            'opted_out' => false,
            'line_type' => null,
            'carrier_name' => null,
            'line_type_checked_at' => null,
            'invalid_reason' => null,
            'invalidated_at' => null,
            'user_marked_valid_at' => null,
            'created_at' => '2026-09-01T10:00:00.000Z',
            'updated_at' => '2026-09-01T10:00:00.000Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wireContactsPage(int $from, int $to, int $total, int $limit, int $offset): array
    {
        return [
            'contacts' => array_map(fn(int $n) => $this->wireContact($n), $from <= $to ? range($from, $to) : []),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wireListsBody(int $count): array
    {
        return [
            'lists' => array_map(fn(int $n) => [
                'id' => 'lst_' . $n,
                'name' => 'List ' . $n,
                'description' => null,
                'contact_count' => 3,
                'created_at' => '2026-09-01T10:00:00.000Z',
                'updated_at' => '2026-09-01T10:00:00.000Z',
            ], $count > 0 ? range(1, $count) : []),
        ];
    }

    // ==================== delete() ====================

    public function testDeleteReportsSuccessOnTheApisNoContent(): void
    {
        $client = $this->createMockClient([new Response(204)]);

        $result = $client->contacts->delete('cnt_1');

        $this->assertSame(['success' => true], $result);
        $this->assertSame('DELETE', $this->history[0]['request']->getMethod());
        $this->assertSame('/api/v1/contacts/cnt_1', $this->history[0]['request']->getUri()->getPath());
    }

    public function testDeleteOfAMissingContactStillThrows(): void
    {
        $client = $this->createMockClient([
            new Response(404, [], json_encode(['error' => 'not_found', 'message' => 'Contact not found'])),
        ]);

        $this->expectException(\Sendly\Exceptions\NotFoundException::class);
        $this->expectExceptionMessage('Contact not found');

        $client->contacts->delete('cnt_missing');
    }

    public function testListsDeleteReportsSuccessOnTheApisNoContent(): void
    {
        $client = $this->createMockClient([new Response(204)]);

        $result = $client->contacts->lists()->delete('lst_1');

        $this->assertSame(['success' => true], $result);
        $this->assertSame('/api/v1/contact-lists/lst_1', $this->history[0]['request']->getUri()->getPath());
    }

    public function testListsRemoveContactReturnsTheApisBody(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['success' => true])),
        ]);

        $result = $client->contacts->lists()->removeContact('lst_1', 'cnt_1');

        $this->assertSame(['success' => true], $result);
        $this->assertSame('/api/v1/contact-lists/lst_1/contacts/cnt_1', $this->history[0]['request']->getUri()->getPath());
    }

    // ==================== lists()->each() ====================

    public function testListsEachReadsExactlyABatchOfListsOnce(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->wireListsBody(100))),
        ]);

        $lists = iterator_to_array($client->contacts->lists()->each(), false);

        $this->assertCount(100, $lists);
        $this->assertCount(100, array_unique(array_column($lists, 'id')));
        $this->assertCount(1, $this->history);
        $this->assertSame('/api/v1/contact-lists', $this->history[0]['request']->getUri()->getPath());
    }

    public function testListsEachReadsEveryListTheApiReturnsInOneRequest(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->wireListsBody(250))),
        ]);

        $lists = iterator_to_array($client->contacts->lists()->each(['batchSize' => 50]), false);

        $this->assertCount(250, $lists);
        $this->assertCount(1, $this->history);

        $doc = (string) (new \ReflectionMethod(\Sendly\Resources\ContactLists::class, 'each'))->getDocComment();
        $this->assertStringNotContainsString('`batchSize` is the page size', $doc);
        $this->assertStringContainsString('`batchSize` has no effect', $doc);
    }

    public function testListsEachPagesByTotalWhenTheApiReportsOne(): void
    {
        $first = $this->wireListsBody(100);
        $first['total'] = 120;
        $second = ['lists' => array_slice($this->wireListsBody(120)['lists'], 100), 'total' => 120];

        $client = $this->createMockClient([
            new Response(200, [], json_encode($first)),
            new Response(200, [], json_encode($second)),
        ]);

        $lists = iterator_to_array($client->contacts->lists()->each(), false);

        $this->assertCount(120, array_unique(array_column($lists, 'id')));
        $this->assertCount(2, $this->history);
        $this->assertSame('100', $this->queryOf(1)['offset']);
    }

    public function testListsEachStopsAtAnEmptyPageEvenWhenTheTotalSaysMore(): void
    {
        $first = $this->wireListsBody(100);
        $first['total'] = 150;

        $client = $this->createMockClient([
            new Response(200, [], json_encode($first)),
            new Response(200, [], json_encode(['lists' => [], 'total' => 150])),
        ]);

        $lists = iterator_to_array($client->contacts->lists()->each(), false);

        $this->assertCount(100, $lists);
        $this->assertCount(2, $this->history);
    }

    // ==================== each() ====================

    public function testEachWithABatchSizeOverTheApiLimitReadsEveryContact(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->wireContactsPage(1, 100, 150, 100, 0))),
            new Response(200, [], json_encode($this->wireContactsPage(101, 150, 150, 100, 100))),
        ]);

        $contacts = iterator_to_array($client->contacts->each(['batchSize' => 200]), false);

        $this->assertCount(150, array_unique(array_column($contacts, 'id')));
        $this->assertCount(2, $this->history);
        $this->assertSame('/api/v1/contacts', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('100', $this->queryOf(0)['limit']);
        $this->assertSame('0', $this->queryOf(0)['offset']);
        $this->assertSame('100', $this->queryOf(1)['offset']);
    }

    public function testEachStopsAtTheTotal(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->wireContactsPage(1, 100, 100, 100, 0))),
        ]);

        $contacts = iterator_to_array($client->contacts->each(), false);

        $this->assertCount(100, $contacts);
        $this->assertCount(1, $this->history);
    }

    public function testEachWithTheDefaultBatchSizeReadsEveryPage(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->wireContactsPage(1, 100, 130, 100, 0))),
            new Response(200, [], json_encode($this->wireContactsPage(101, 130, 130, 100, 100))),
        ]);

        $contacts = iterator_to_array($client->contacts->each(['listId' => 'lst_1']), false);

        $this->assertCount(130, $contacts);
        $this->assertSame('100', $this->queryOf(1)['offset']);
        $this->assertSame('lst_1', $this->queryOf(1)['list_id']);
    }

    public function testEachWithoutATotalReadsOnUntilAShortPage(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode(['contacts' => array_map(fn(int $n) => $this->wireContact($n), range(1, 100))])),
            new Response(200, [], json_encode(['contacts' => array_map(fn(int $n) => $this->wireContact($n), range(101, 130))])),
        ]);

        $contacts = iterator_to_array($client->contacts->each(), false);

        $this->assertCount(130, array_unique(array_column($contacts, 'id')));
        $this->assertCount(2, $this->history);
        $this->assertSame('100', $this->queryOf(1)['offset']);
    }

    public function testEachStopsAtAnEmptyPageEvenWhenTheTotalSaysMore(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->wireContactsPage(1, 100, 150, 100, 0))),
            new Response(200, [], json_encode($this->wireContactsPage(101, 100, 150, 100, 100))),
        ]);

        $contacts = iterator_to_array($client->contacts->each(), false);

        $this->assertCount(100, $contacts);
        $this->assertCount(2, $this->history);
    }
}
