<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\TestCase;
use Sendly\Webhooks;
use Sendly\WebhookEvent;
use Sendly\WebhookMessageData;
use Sendly\WebhookVerificationData;
use Sendly\Exceptions\WebhookSignatureException;

/**
 * Tests for Webhooks: verifySignature(), parseEvent(), generateSignature()
 */
class WebhooksTest extends TestCase
{
    private string $secret = 'test_webhook_secret_key';

    // ==================== verifySignature() Tests ====================

    public function testVerifySignatureSuccess(): void
    {
        $payload = '{"id":"evt_123","type":"message.delivered","data":{"message_id":"msg_123"},"created_at":"2024-01-01T12:00:00Z"}';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $this->secret);

        $result = Webhooks::verifySignature($payload, $signature, $this->secret);

        $this->assertTrue($result);
    }

    public function testVerifySignatureInvalidSignature(): void
    {
        $payload = '{"id":"evt_123","type":"message.delivered"}';
        $signature = 'sha256=invalid_signature_here';

        $result = Webhooks::verifySignature($payload, $signature, $this->secret);

        $this->assertFalse($result);
    }

    public function testVerifySignatureWithEmptyPayload(): void
    {
        $signature = 'sha256=' . hash_hmac('sha256', '', $this->secret);

        $result = Webhooks::verifySignature('', $signature, $this->secret);

        $this->assertFalse($result);
    }

    public function testVerifySignatureWithEmptySignature(): void
    {
        $payload = '{"id":"evt_123"}';

        $result = Webhooks::verifySignature($payload, '', $this->secret);

        $this->assertFalse($result);
    }

    public function testVerifySignatureWithEmptySecret(): void
    {
        $payload = '{"id":"evt_123"}';
        $signature = 'sha256=some_signature';

        $result = Webhooks::verifySignature($payload, $signature, '');

        $this->assertFalse($result);
    }

    public function testVerifySignatureWithWrongSecret(): void
    {
        $payload = '{"id":"evt_123","type":"message.delivered"}';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'correct_secret');

        $result = Webhooks::verifySignature($payload, $signature, 'wrong_secret');

        $this->assertFalse($result);
    }

    public function testVerifySignatureWithModifiedPayload(): void
    {
        $originalPayload = '{"id":"evt_123","type":"message.delivered"}';
        $signature = 'sha256=' . hash_hmac('sha256', $originalPayload, $this->secret);

        $modifiedPayload = '{"id":"evt_456","type":"message.delivered"}';
        $result = Webhooks::verifySignature($modifiedPayload, $signature, $this->secret);

        $this->assertFalse($result);
    }

    public function testVerifySignatureTimingSafeComparison(): void
    {
        // Test that verification uses timing-safe comparison (hash_equals)
        $payload = '{"id":"evt_123"}';
        $correctSignature = 'sha256=' . hash_hmac('sha256', $payload, $this->secret);

        // Create a signature that differs by only one character
        $incorrectSignature = substr($correctSignature, 0, -1) . 'x';

        $result = Webhooks::verifySignature($payload, $incorrectSignature, $this->secret);

        $this->assertFalse($result);
    }

    // ==================== parseEvent() Tests ====================

    public function testParseEventSuccess(): void
    {
        $payload = json_encode([
            'id' => 'evt_123',
            'type' => 'message.delivered',
            'data' => [
                'message_id' => 'msg_123',
                'status' => 'delivered',
                'to' => '+15551234567',
                'from' => 'MyBrand',
                'delivered_at' => '2024-01-01T12:05:00Z',
                'segments' => 1,
                'credits_used' => 1,
            ],
            'created_at' => '2024-01-01T12:00:00Z',
            'api_version' => '2024-01-01',
        ]);
        $signature = Webhooks::generateSignature($payload, $this->secret);

        $event = Webhooks::parseEvent($payload, $signature, $this->secret);

        $this->assertInstanceOf(WebhookEvent::class, $event);
        $this->assertSame('evt_123', $event->id);
        $this->assertSame('message.delivered', $event->type);
        $this->assertSame('2024-01-01T12:00:00Z', $event->created);
        $this->assertSame('2024-01-01', $event->apiVersion);

        // Check data
        $this->assertSame('msg_123', $event->data->id);
        $this->assertSame('delivered', $event->data->status);
        $this->assertSame('+15551234567', $event->data->to);
        $this->assertSame('MyBrand', $event->data->from);
        $this->assertSame('2024-01-01T12:05:00Z', $event->data->deliveredAt);
        $this->assertSame(1, $event->data->segments);
        $this->assertSame(1, $event->data->creditsUsed);
    }

    public function testParseEventMessageFailed(): void
    {
        $payload = json_encode([
            'id' => 'evt_456',
            'type' => 'message.failed',
            'data' => [
                'message_id' => 'msg_456',
                'status' => 'failed',
                'to' => '+15559876543',
                'from' => 'MyBrand',
                'error' => 'Invalid destination number',
                'error_code' => 'INVALID_NUMBER',
                'failed_at' => '2024-01-01T12:05:00Z',
                'segments' => 1,
                'credits_used' => 0,
            ],
            'created_at' => '2024-01-01T12:00:00Z',
        ]);
        $signature = Webhooks::generateSignature($payload, $this->secret);

        $event = Webhooks::parseEvent($payload, $signature, $this->secret);

        $this->assertSame('message.failed', $event->type);
        $this->assertSame('msg_456', $event->data->id);
        $this->assertSame('failed', $event->data->status);
        $this->assertSame('Invalid destination number', $event->data->error);
        $this->assertSame('INVALID_NUMBER', $event->data->errorCode);
        $this->assertSame('2024-01-01T12:05:00Z', $event->data->failedAt);
    }

    public function testParseEventWithInvalidSignature(): void
    {
        $payload = json_encode([
            'id' => 'evt_123',
            'type' => 'message.delivered',
            'data' => ['message_id' => 'msg_123', 'status' => 'delivered', 'to' => '+15551234567', 'from' => '', 'segments' => 1, 'credits_used' => 1],
            'created_at' => '2024-01-01T12:00:00Z',
        ]);
        $invalidSignature = 'sha256=invalid_signature';

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage('Invalid webhook signature');

        Webhooks::parseEvent($payload, $invalidSignature, $this->secret);
    }

    public function testParseEventWithMissingId(): void
    {
        $payload = json_encode([
            // 'id' missing
            'type' => 'message.delivered',
            'data' => ['message_id' => 'msg_123', 'status' => 'delivered', 'to' => '+15551234567', 'from' => '', 'segments' => 1, 'credits_used' => 1],
            'created_at' => '2024-01-01T12:00:00Z',
        ]);
        $signature = Webhooks::generateSignature($payload, $this->secret);

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage('Invalid event structure');

        Webhooks::parseEvent($payload, $signature, $this->secret);
    }

    public function testParseEventWithMissingType(): void
    {
        $payload = json_encode([
            'id' => 'evt_123',
            // 'type' missing
            'data' => ['message_id' => 'msg_123', 'status' => 'delivered', 'to' => '+15551234567', 'from' => '', 'segments' => 1, 'credits_used' => 1],
            'created_at' => '2024-01-01T12:00:00Z',
        ]);
        $signature = Webhooks::generateSignature($payload, $this->secret);

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage('Invalid event structure');

        Webhooks::parseEvent($payload, $signature, $this->secret);
    }

    public function testParseEventWithMissingData(): void
    {
        $payload = json_encode([
            'id' => 'evt_123',
            'type' => 'message.delivered',
            // 'data' missing
            'created_at' => '2024-01-01T12:00:00Z',
        ]);
        $signature = Webhooks::generateSignature($payload, $this->secret);

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage('Invalid event structure');

        Webhooks::parseEvent($payload, $signature, $this->secret);
    }

    public function testParseEventWithMissingCreatedAt(): void
    {
        $payload = json_encode([
            'id' => 'evt_123',
            'type' => 'message.delivered',
            'data' => ['message_id' => 'msg_123', 'status' => 'delivered', 'to' => '+15551234567', 'from' => '', 'segments' => 1, 'credits_used' => 1],
            // 'created_at' missing
        ]);
        $signature = Webhooks::generateSignature($payload, $this->secret);

        // created_at is no longer required; only id, type, and data are.
        $event = Webhooks::parseEvent($payload, $signature, $this->secret);

        $this->assertSame('evt_123', $event->id);
        $this->assertSame(0, $event->created);
    }

    public function testParseEventWithInvalidJson(): void
    {
        $payload = 'invalid json {{{';
        $signature = Webhooks::generateSignature($payload, $this->secret);

        $this->expectException(\JsonException::class);

        Webhooks::parseEvent($payload, $signature, $this->secret);
    }

    public function testParseEventWithDefaultApiVersion(): void
    {
        $payload = json_encode([
            'id' => 'evt_123',
            'type' => 'message.delivered',
            'data' => ['message_id' => 'msg_123', 'status' => 'delivered', 'to' => '+15551234567', 'from' => '', 'segments' => 1, 'credits_used' => 1],
            'created_at' => '2024-01-01T12:00:00Z',
            // 'api_version' not provided
        ]);
        $signature = Webhooks::generateSignature($payload, $this->secret);

        $event = Webhooks::parseEvent($payload, $signature, $this->secret);

        $this->assertSame('2024-01', $event->apiVersion); // default value
    }

    public function testParseEventWithOptionalFields(): void
    {
        $payload = json_encode([
            'id' => 'evt_123',
            'type' => 'message.delivered',
            'data' => [
                'message_id' => 'msg_123',
                'status' => 'delivered',
                'to' => '+15551234567',
                // 'from' optional
                // 'error' optional
                // 'error_code' optional
                // 'delivered_at' optional
                // 'failed_at' optional
                'segments' => 1,
                'credits_used' => 1,
            ],
            'created_at' => '2024-01-01T12:00:00Z',
        ]);
        $signature = Webhooks::generateSignature($payload, $this->secret);

        $event = Webhooks::parseEvent($payload, $signature, $this->secret);

        // A field the payload did not carry is null, not a stand-in value.
        $this->assertNull($event->data->from);
        $this->assertNull($event->data->error);
        $this->assertNull($event->data->errorCode);
        $this->assertNull($event->data->deliveredAt);
        $this->assertNull($event->data->failedAt);
    }

    // ==================== generateSignature() Tests ====================

    public function testGenerateSignature(): void
    {
        $payload = '{"id":"evt_123","type":"message.delivered"}';

        $signature = Webhooks::generateSignature($payload, $this->secret);

        $this->assertStringStartsWith('sha256=', $signature);
        $this->assertSame(71, strlen($signature)); // 'sha256=' (7 chars) + 64 hex chars
    }

    public function testGenerateSignatureConsistency(): void
    {
        $payload = '{"id":"evt_123"}';

        $signature1 = Webhooks::generateSignature($payload, $this->secret);
        $signature2 = Webhooks::generateSignature($payload, $this->secret);

        $this->assertSame($signature1, $signature2);
    }

    public function testGenerateSignatureDifferentSecrets(): void
    {
        $payload = '{"id":"evt_123"}';

        $signature1 = Webhooks::generateSignature($payload, 'secret1');
        $signature2 = Webhooks::generateSignature($payload, 'secret2');

        $this->assertNotSame($signature1, $signature2);
    }

    public function testGenerateSignatureDifferentPayloads(): void
    {
        $signature1 = Webhooks::generateSignature('{"id":"evt_123"}', $this->secret);
        $signature2 = Webhooks::generateSignature('{"id":"evt_456"}', $this->secret);

        $this->assertNotSame($signature1, $signature2);
    }

    public function testGenerateAndVerifySignatureRoundTrip(): void
    {
        $payload = '{"id":"evt_123","type":"message.delivered","data":{"message_id":"msg_123","status":"delivered","to":"+15551234567","from":"","segments":1,"credits_used":1},"created_at":"2024-01-01T12:00:00Z"}';

        $signature = Webhooks::generateSignature($payload, $this->secret);
        $verified = Webhooks::verifySignature($payload, $signature, $this->secret);

        $this->assertTrue($verified);
    }

    // ==================== data.object extraction ====================

    /**
     * @param array<string, mixed> $object
     */
    private function parse(string $type, array $object): WebhookEvent
    {
        $payload = json_encode([
            'id' => 'evt_' . substr(md5($type), 0, 8),
            'type' => $type,
            'api_version' => '2024-01',
            'created' => 1767225600,
            'livemode' => true,
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR);

        return Webhooks::parseEvent($payload, Webhooks::generateSignature($payload, $this->secret), $this->secret);
    }

    public function testMessageEventKeepsMessageViewAndExposesRawObject(): void
    {
        $event = $this->parse('message.delivered', [
            'id' => 'msg_123',
            'to' => '+15551234567',
            'from' => '+15559876543',
            'text' => 'Hello',
            'status' => 'delivered',
            'direction' => 'outbound',
            'segments' => 2,
            'credits_used' => 4,
        ]);

        $this->assertInstanceOf(WebhookMessageData::class, $event->data);
        $this->assertSame('msg_123', $event->data->id);
        $this->assertSame(2, $event->data->segments);
        $this->assertSame(4, $event->data->creditsUsed);

        // The same object is also readable verbatim.
        $this->assertSame('+15551234567', $event->object['to']);
        $this->assertSame(4, $event->object['credits_used']);
        $this->assertTrue($event->livemode);
        $this->assertSame(1767225600, $event->created);
    }

    public function testLifecycleEventHasNoMessageViewButKeepsItsObject(): void
    {
        $event = $this->parse('rcs_agent.live', [
            'agent_id' => 'bb22cc33-dd44-4e55-9f66-001122334455',
            'name' => 'Acme Support',
            'stage' => 'live',
            'organization_id' => 'org_1',
        ]);

        $this->assertNull($event->data, 'a lifecycle event has no message view');
        $this->assertSame('bb22cc33-dd44-4e55-9f66-001122334455', $event->object['agent_id']);
        $this->assertSame('live', $event->get('stage'));
        $this->assertSame(['agent_id', 'name', 'stage', 'organization_id'], array_keys($event->object));
    }

    public function testLifecycleEventInventsNoMessageFields(): void
    {
        $event = $this->parse('port.completed', [
            'port_request_id' => 'prt_1',
            'phone_number' => '+15555550166',
            'status' => 'completed',
        ]);

        $this->assertNull($event->data);
        foreach (['to', 'from', 'text', 'segments', 'credits_used', 'direction'] as $field) {
            $this->assertArrayNotHasKey($field, $event->object);
            $this->assertNull($event->get($field));
        }
    }

    public function testCallEventKeepsNullFromAndTo(): void
    {
        $event = $this->parse('call.started', [
            'id' => 'call_1',
            'object' => 'call',
            'kind' => 'internal',
            'status' => 'active',
            'from' => null,
            'to' => null,
            'duration_secs' => 0,
            'hangup_class' => null,
        ]);

        $this->assertNull($event->data);
        $this->assertArrayHasKey('from', $event->object);
        $this->assertNull($event->object['from'], 'a null number must stay null, not become ""');
        $this->assertNull($event->object['to']);
        $this->assertNull($event->object['hangup_class']);
        $this->assertSame(0, $event->object['duration_secs']);
        $this->assertSame('call', $event->object['object'], 'data.object.object is not the envelope');
    }

    public function testContactAutoFlaggedDoesNotMisattributeTheMessageId(): void
    {
        $event = $this->parse('contact.auto_flagged', [
            'id' => 'contact_5e4d3c2b',
            'phone_number' => '+15555550144',
            'invalid_reason' => 'landline',
            'message_id' => 'msg_2d1f8a34',
            'error_code' => 'E003',
        ]);

        $this->assertNull($event->data, 'a contact is not a message');
        $this->assertSame('msg_2d1f8a34', $event->object['message_id']);
        $this->assertSame('contact_5e4d3c2b', $event->object['id']);
        $this->assertNotSame($event->object['id'], $event->object['message_id']);
    }

    public function testUnknownEventTypeStaysReadable(): void
    {
        $event = $this->parse('something.invented_later', [
            'id' => 'obj_1',
            'some_new_field' => 'a value no SDK has a type for',
        ]);

        $this->assertSame('something.invented_later', $event->type);
        $this->assertNull($event->data);
        $this->assertSame('a value no SDK has a type for', $event->get('some_new_field'));
        $this->assertSame('fallback', $event->get('absent_key', 'fallback'));
    }

    public function testWhatsAppTemplateCamelCaseTimestampsSurvive(): void
    {
        $event = $this->parse('whatsapp_template.approved', [
            'id' => 'tpl_1',
            'name' => 'appointment_reminder',
            'status' => 'APPROVED',
            'qualityRating' => null,
            'createdAt' => '2026-01-01T00:00:00.000Z',
            'updatedAt' => '2026-01-01T12:00:00.000Z',
        ]);

        $this->assertSame('2026-01-01T00:00:00.000Z', $event->object['createdAt']);
        $this->assertSame('2026-01-01T12:00:00.000Z', $event->object['updatedAt']);
        $this->assertArrayHasKey('qualityRating', $event->object);
        $this->assertNull($event->object['qualityRating']);
    }

    public function testMessageViewReadsCamelCaseTimestamps(): void
    {
        $event = $this->parse('message.delivered', [
            'id' => 'msg_1',
            'status' => 'delivered',
            'createdAt' => '2026-01-01T00:00:00.000Z',
            'deliveredAt' => '2026-01-01T00:00:05.000Z',
        ]);

        $this->assertInstanceOf(WebhookMessageData::class, $event->data);
        $this->assertSame('2026-01-01T00:00:00.000Z', $event->data->createdAt);
        $this->assertSame('2026-01-01T00:00:05.000Z', $event->data->deliveredAt);
    }

    public function testVerificationEventExposesTheVerificationView(): void
    {
        $event = $this->parse('verification.verified', [
            'id' => 'ver_1',
            'organization_id' => 'org_1',
            'phone' => '+15555550123',
            'status' => 'verified',
            'delivery_status' => 'delivered',
            'attempts' => 1,
            'max_attempts' => 3,
            'verified_at' => 1767225650,
            'app_name' => 'Acme Login',
        ]);

        $this->assertNull($event->data, 'a verification is not a message');

        $verification = $event->verification();
        $this->assertInstanceOf(WebhookVerificationData::class, $verification);
        $this->assertSame('ver_1', $verification->id);
        $this->assertSame('+15555550123', $verification->phone);
        $this->assertSame('delivered', $verification->deliveryStatus);
        $this->assertSame(1, $verification->attempts);
        $this->assertSame(3, $verification->maxAttempts);
        $this->assertSame(1767225650, $verification->verifiedAt);
        $this->assertSame('Acme Login', $verification->appName);
        $this->assertNull($verification->templateId, 'an absent field stays null');
    }

    public function testVerificationViewIsNullForOtherEventTypes(): void
    {
        $this->assertNull($this->parse('rcs_agent.live', ['agent_id' => 'a1'])->verification());
    }

    public function testObjectAsUsesFromArrayWhenPresent(): void
    {
        $event = $this->parse('verification.expired', ['id' => 'ver_2', 'phone' => '+15555550123']);

        $verification = $event->objectAs(WebhookVerificationData::class);

        $this->assertSame('ver_2', $verification->id);
        $this->assertNull($verification->status);
    }

    public function testObjectAsFallsBackToTheArrayConstructor(): void
    {
        $event = $this->parse('rcs_agent.live', ['agent_id' => 'agent_9', 'stage' => 'live']);

        $agent = $event->objectAs(RcsAgentLiveFixture::class);

        $this->assertSame('agent_9', $agent->agentId);
        $this->assertSame('live', $agent->stage);
    }

    public function testObjectAsRejectsAnUnknownClass(): void
    {
        $event = $this->parse('rcs_agent.live', ['agent_id' => 'agent_9']);

        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore-next-line intentionally unknown class */
        $event->objectAs('Sendly\\Tests\\NoSuchClass');
    }

    public function testIsMessageEvent(): void
    {
        $this->assertTrue(WebhookEvent::isMessageEvent('message.delivered'));
        $this->assertTrue(WebhookEvent::isMessageEvent('message.received'));
        $this->assertFalse(WebhookEvent::isMessageEvent('call.completed'));
        $this->assertFalse(WebhookEvent::isMessageEvent('whatsapp_template.approved'));
    }

    public function testEventCanBeBuiltFromAnObjectDecode(): void
    {
        $json = json_encode([
            'id' => 'evt_obj',
            'type' => 'call.completed',
            'created' => 1767225600,
            'livemode' => true,
            'data' => ['object' => ['id' => 'call_1', 'from' => null, 'duration_secs' => 96]],
        ], JSON_THROW_ON_ERROR);

        $event = new WebhookEvent(json_decode($json, false, 512, JSON_THROW_ON_ERROR));

        $this->assertSame('evt_obj', $event->id);
        $this->assertNull($event->data);
        $this->assertSame(['id' => 'call_1', 'from' => null, 'duration_secs' => 96], $event->object);
    }

    public function testFlatDataPayloadStillResolves(): void
    {
        $payload = json_encode([
            'id' => 'evt_flat',
            'type' => 'message.delivered',
            'data' => ['message_id' => 'msg_flat', 'status' => 'delivered'],
        ], JSON_THROW_ON_ERROR);
        $event = Webhooks::parseEvent($payload, Webhooks::generateSignature($payload, $this->secret), $this->secret);

        $this->assertInstanceOf(WebhookMessageData::class, $event->data);
        $this->assertSame('msg_flat', $event->data->id);
        $this->assertSame('msg_flat', $event->object['message_id']);
    }
}

/**
 * A lifecycle view built the way every data class in this SDK is: one
 * constructor taking the decoded array.
 */
class RcsAgentLiveFixture
{
    public readonly ?string $agentId;
    public readonly ?string $stage;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $agentId = $data['agent_id'] ?? null;
        $stage = $data['stage'] ?? null;
        $this->agentId = is_string($agentId) ? $agentId : null;
        $this->stage = is_string($stage) ? $stage : null;
    }
}
