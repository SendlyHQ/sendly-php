<?php

declare(strict_types=1);

namespace Sendly\Tests;

use PHPUnit\Framework\TestCase;
use Sendly\Webhooks;
use Sendly\WebhookEvent;
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
        $this->assertSame('2024-01-01T12:00:00Z', $event->createdAt);
        $this->assertSame('2024-01-01', $event->apiVersion);

        // Check data
        $this->assertSame('msg_123', $event->data->messageId);
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
        $this->assertSame('msg_456', $event->data->messageId);
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

        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage('Invalid event structure');

        Webhooks::parseEvent($payload, $signature, $this->secret);
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

        $this->assertSame('2024-01-01', $event->apiVersion); // default value
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

        $this->assertSame('', $event->data->from);
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
}
