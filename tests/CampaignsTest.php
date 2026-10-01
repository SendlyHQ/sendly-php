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
 * Tests for the Campaigns resource
 */
class CampaignsTest extends TestCase
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
    private function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function wireCampaign(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'userId' => 'user_1',
            'organizationId' => 'org_1',
            'name' => 'Launch',
            'status' => 'draft',
            'messageText' => 'Hello {{name}}',
            'fromSender' => null,
            'targetType' => 'contact_list',
            'targetListId' => 'lst_1',
            'manualRecipients' => null,
            'excludeOptedOut' => true,
            'sendNow' => false,
            'scheduledAt' => null,
            'timezone' => 'America/New_York',
            'batchId' => null,
            'totalRecipients' => 0,
            'estimatedCredits' => 0,
            'sentCount' => 0,
            'deliveredCount' => 0,
            'failedCount' => 0,
            'creditsUsed' => 0,
            'creditsRefunded' => 0,
            'createdAt' => '2026-09-01T10:00:00.000Z',
            'updatedAt' => '2026-09-01T10:00:00.000Z',
            'sentAt' => null,
            'completedAt' => null,
            'text' => 'Hello {{name}}',
            'contact_list_ids' => ['lst_1'],
            'created_at' => '2026-09-01T10:00:00.000Z',
            'updated_at' => '2026-09-01T10:00:00.000Z',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function wireCampaignsPage(int $from, int $to, int $total, int $limit, int $offset): array
    {
        return [
            'campaigns' => array_map(fn(int $n) => $this->wireCampaign('camp_' . $n), range($from, $to)),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    // ==================== create() / update() ====================

    public function testCreateWithMessageTypeStillSends(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->wireCampaign('camp_1'))),
        ]);

        $client->campaigns->create('Launch', 'Hello {{name}}', [
            'contactListId' => 'lst_1',
            'messageType' => 'transactional',
        ]);

        $this->assertCount(1, $this->history);
        $this->assertSame('/api/v1/campaigns', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('Hello {{name}}', $this->sentBody()['text']);
        $this->assertSame('lst_1', $this->sentBody()['contactListId']);
        $this->assertSame('transactional', $this->sentBody()['messageType']);
    }

    public function testUpdateWithMessageTypeStillSends(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->wireCampaign('camp_1'))),
        ]);

        $client->campaigns->update('camp_1', ['text' => 'New text', 'messageType' => 'marketing']);

        $this->assertSame('PATCH', $this->history[0]['request']->getMethod());
        $this->assertSame('New text', $this->sentBody()['text']);
    }

    public function testCreateWithoutMessageTypeSendsOnlyWhatWasGiven(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->wireCampaign('camp_1'))),
        ]);

        $client->campaigns->create('Launch', 'Hello', ['contactListIds' => ['lst_1']]);

        $this->assertSame(['name' => 'Launch', 'text' => 'Hello', 'contactListIds' => ['lst_1']], $this->sentBody());
    }

    public function testMessageTypeRaisesNoErrorUnderAHandlerThatThrowsOnEveryError(): void
    {
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->wireCampaign('camp_1'))),
            new Response(201, [], json_encode($this->wireCampaign('camp_2'))),
            new Response(200, [], json_encode($this->wireCampaign('camp_1', ['messageText' => 'New text', 'text' => 'New text']))),
        ]);

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $created = $client->campaigns->create('Launch', 'Hello {{name}}', [
                'contactListId' => 'lst_1',
                'messageType' => 'marketing',
            ]);
            $createdWithNull = $client->campaigns->create('Launch', 'Hello {{name}}', [
                'contactListId' => 'lst_1',
                'messageType' => null,
            ]);
            $updated = $client->campaigns->update('camp_1', ['text' => 'New text', 'messageType' => 'transactional']);
        } finally {
            restore_error_handler();
        }

        $this->assertCount(3, $this->history);
        $this->assertSame('camp_1', $created['id']);
        $this->assertSame('camp_2', $createdWithNull['id']);
        $this->assertSame('New text', $updated['text']);
    }

    public function testCreateAndUpdateAcceptMultibyteTextWithinTheCharacterLimit(): void
    {
        $text = str_repeat('ü', 1200);
        $client = $this->createMockClient([
            new Response(201, [], json_encode($this->wireCampaign('camp_1', ['text' => $text]))),
            new Response(200, [], json_encode($this->wireCampaign('camp_1', ['text' => $text]))),
        ]);

        $client->campaigns->create('Launch', $text);
        $client->campaigns->update('camp_1', ['text' => $text]);

        $this->assertCount(2, $this->history);
        $this->assertSame($text, $this->sentBody(1)['text']);
    }

    public function testCreateStillRejectsTextOverTheCharacterLimit(): void
    {
        $client = $this->createMockClient([]);

        $this->expectException(\Sendly\Exceptions\ValidationException::class);
        $this->expectExceptionMessage('Campaign text exceeds maximum length (1600 characters)');

        $client->campaigns->create('Launch', str_repeat('ü', 1601));
    }

    public function testCreateStillRejectsAnInvalidMessageType(): void
    {
        $client = $this->createMockClient([]);

        $this->expectException(\Sendly\Exceptions\ValidationException::class);

        try {
            $client->campaigns->create('Launch', 'Hello', ['messageType' => 'promo']);
        } finally {
            $this->assertCount(0, $this->history);
        }
    }

    // ==================== statuses ====================

    public function testStatusConstantsCoverWhatTheApiStores(): void
    {
        $this->assertSame('draft', \Sendly\Resources\Campaigns::STATUS_DRAFT);
        $this->assertSame('scheduled', \Sendly\Resources\Campaigns::STATUS_SCHEDULED);
        $this->assertSame('sending', \Sendly\Resources\Campaigns::STATUS_SENDING);
        $this->assertSame('completed', \Sendly\Resources\Campaigns::STATUS_COMPLETED);
        $this->assertSame('failed', \Sendly\Resources\Campaigns::STATUS_FAILED);
        $this->assertSame('cancelled', \Sendly\Resources\Campaigns::STATUS_CANCELLED);
    }

    public function testASentCampaignReadsAsCompleted(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'campaigns' => [$this->wireCampaign('camp_1', ['status' => 'completed', 'sentCount' => 10])],
                'total' => 1,
                'limit' => 20,
                'offset' => 0,
            ])),
        ]);

        $result = $client->campaigns->list(['status' => \Sendly\Resources\Campaigns::STATUS_SENT]);

        $this->assertSame('sent', $this->queryOf(0)['status']);
        $this->assertSame(\Sendly\Resources\Campaigns::STATUS_COMPLETED, $result['campaigns'][0]['status']);
    }

    // ==================== delete() ====================

    public function testDeleteReportsSuccessOnTheApisNoContent(): void
    {
        $client = $this->createMockClient([new Response(204)]);

        $result = $client->campaigns->delete('camp_1');

        $this->assertSame(['success' => true], $result);
        $this->assertSame('DELETE', $this->history[0]['request']->getMethod());
        $this->assertSame('/api/v1/campaigns/camp_1', $this->history[0]['request']->getUri()->getPath());
    }

    // ==================== each() ====================

    public function testEachWithABatchSizeOverTheListLimitReadsEveryCampaign(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->wireCampaignsPage(1, 100, 150, 100, 0))),
            new Response(200, [], json_encode($this->wireCampaignsPage(101, 150, 150, 100, 100))),
        ]);

        $campaigns = iterator_to_array($client->campaigns->each(['batchSize' => 200]), false);

        $this->assertCount(150, array_unique(array_column($campaigns, 'id')));
        $this->assertCount(2, $this->history);
        $this->assertSame('/api/v1/campaigns', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('100', $this->queryOf(0)['limit']);
        $this->assertSame('100', $this->queryOf(1)['offset']);
    }

    public function testEachStopsAtTheTotal(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->wireCampaignsPage(1, 20, 20, 20, 0))),
        ]);

        $campaigns = iterator_to_array($client->campaigns->each(['batchSize' => 20, 'status' => 'completed']), false);

        $this->assertCount(20, $campaigns);
        $this->assertCount(1, $this->history);
        $this->assertSame('completed', $this->queryOf(0)['status']);
    }

    public function testEachWithoutATotalReadsOnUntilAShortPage(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'campaigns' => array_map(fn(int $n) => $this->wireCampaign('camp_' . $n), range(1, 100)),
            ])),
            new Response(200, [], json_encode([
                'campaigns' => array_map(fn(int $n) => $this->wireCampaign('camp_' . $n), range(101, 130)),
            ])),
        ]);

        $campaigns = iterator_to_array($client->campaigns->each(), false);

        $this->assertCount(130, array_unique(array_column($campaigns, 'id')));
        $this->assertCount(2, $this->history);
        $this->assertSame('100', $this->queryOf(1)['offset']);
    }

    public function testEachStopsAtAnEmptyPageEvenWhenTheTotalSaysMore(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode($this->wireCampaignsPage(1, 100, 150, 100, 0))),
            new Response(200, [], json_encode(['campaigns' => [], 'total' => 150, 'limit' => 100, 'offset' => 100])),
        ]);

        $campaigns = iterator_to_array($client->campaigns->each(), false);

        $this->assertCount(100, $campaigns);
        $this->assertCount(2, $this->history);
    }
}
