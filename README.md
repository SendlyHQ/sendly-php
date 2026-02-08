<p align="center">
  <img src="https://raw.githubusercontent.com/SendlyHQ/sendly-php/main/.github/header.svg" alt="Sendly PHP SDK" />
</p>

<p align="center">
  <a href="https://packagist.org/packages/sendly/sendly"><img src="https://img.shields.io/packagist/v/sendly/sendly.svg?style=flat-square" alt="Packagist" /></a>
  <a href="https://github.com/SendlyHQ/sendly-php/blob/main/LICENSE"><img src="https://img.shields.io/github/license/SendlyHQ/sendly-php?style=flat-square" alt="license" /></a>
</p>

# Sendly PHP SDK

Official PHP SDK for the Sendly SMS API.

## Requirements

- PHP 8.1+
- Composer

## Installation

```bash
composer require sendly/sendly-php
```

## Quick Start

```php
<?php

use Sendly\Sendly;

$client = new Sendly('sk_live_v1_your_api_key');

// Send an SMS
$message = $client->messages()->send(
    '+15551234567',
    'Hello from Sendly!'
);

echo $message->id;     // "msg_abc123"
echo $message->status; // "queued"
```

## Prerequisites for Live Messaging

Before sending live SMS messages, you need:

1. **Business Verification** - Complete verification in the [Sendly dashboard](https://sendly.live/dashboard)
   - **International**: Instant approval (just provide Sender ID)
   - **US/Canada**: Requires carrier approval (3-7 business days)

2. **Credits** - Add credits to your account
   - Test keys (`sk_test_*`) work without credits (sandbox mode)
   - Live keys (`sk_live_*`) require credits for each message

3. **Live API Key** - Generate after verification + credits
   - Dashboard → API Keys → Create Live Key

### Test vs Live Keys

| Key Type | Prefix | Credits Required | Verification Required | Use Case |
|----------|--------|------------------|----------------------|----------|
| Test | `sk_test_v1_*` | No | No | Development, testing |
| Live | `sk_live_v1_*` | Yes | Yes | Production messaging |

> **Note**: You can start development immediately with a test key. Messages to sandbox test numbers are free and don't require verification.

## Configuration

```php
$client = new Sendly('sk_live_v1_xxx', [
    'baseUrl' => 'https://sendly.live/api/v1',
    'timeout' => 60,
    'maxRetries' => 5,
]);
```

## Messages

### Send an SMS

```php
// Marketing message (default)
$message = $client->messages()->send(
    '+15551234567',
    'Check out our new features!'
);

// Transactional message (bypasses quiet hours)
$message = $client->messages()->send(
    '+15551234567',
    'Your verification code is: 123456',
    'transactional'
);

// With custom metadata (max 4KB)
$message = $client->messages()->send(
    '+15551234567',
    'Your order #12345 has shipped!',
    null, // messageType
    ['order_id' => '12345', 'customer_id' => 'cust_abc']
);

echo $message->id;
echo $message->status;
echo $message->creditsUsed;
```

### List Messages

```php
// Basic listing
$messages = $client->messages()->list(['limit' => 50]);

foreach ($messages as $msg) {
    echo $msg->to;
}

// With filters
$messages = $client->messages()->list([
    'status' => 'delivered',
    'to' => '+15551234567',
    'limit' => 20,
    'offset' => 0,
]);

// Pagination info
echo $messages->total;
echo $messages->hasMore;
```

### Get a Message

```php
$message = $client->messages()->get('msg_abc123');

echo $message->to;
echo $message->text;
echo $message->status;
echo $message->deliveredAt?->format('Y-m-d H:i:s');
```

### Scheduling Messages

```php
// Schedule a message for future delivery
$scheduled = $client->messages()->schedule(
    '+15551234567',
    'Your appointment is tomorrow!',
    '2025-01-15T10:00:00Z'
);

echo $scheduled->id;
echo $scheduled->scheduledAt;

// List scheduled messages
$result = $client->messages()->listScheduled();
foreach ($result as $msg) {
    echo "{$msg->id}: {$msg->scheduledAt}\n";
}

// Get a specific scheduled message
$msg = $client->messages()->getScheduled('sched_xxx');

// Cancel a scheduled message (refunds credits)
$result = $client->messages()->cancelScheduled('sched_xxx');
echo "Refunded: {$result->creditsRefunded} credits";
```

### Batch Messages

```php
// Send multiple messages in one API call (up to 1000)
$batch = $client->messages()->sendBatch([
    ['to' => '+15551234567', 'text' => 'Hello User 1!'],
    ['to' => '+15559876543', 'text' => 'Hello User 2!'],
    ['to' => '+15551112222', 'text' => 'Hello User 3!'],
]);

echo $batch->batchId;
echo "Queued: {$batch->queued}";
echo "Failed: {$batch->failed}";
echo "Credits used: {$batch->creditsUsed}";

// Get batch status
$status = $client->messages()->getBatch('batch_xxx');

// List all batches
$batches = $client->messages()->listBatches();

// Preview batch (dry run) - validates without sending
$preview = $client->messages()->previewBatch([
    ['to' => '+15551234567', 'text' => 'Hello User 1!'],
    ['to' => '+447700900123', 'text' => 'Hello UK!'],
]);
echo "Total credits needed: {$preview->totalCredits}";
echo "Valid: {$preview->valid}, Invalid: {$preview->invalid}";
```

### Iterate All Messages

```php
// Auto-pagination with generator
foreach ($client->messages()->each() as $message) {
    echo "{$message->id}: {$message->to}\n";
}

// With filters
foreach ($client->messages()->each(['status' => 'delivered']) as $message) {
    echo "Delivered: {$message->id}\n";
}
```

## Webhooks

```php
// Create a webhook endpoint
$webhook = $client->webhooks()->create(
    'https://example.com/webhooks/sendly',
    ['message.delivered', 'message.failed']
);

echo $webhook->id;
echo $webhook->secret; // Store securely!

// List all webhooks
$webhooks = $client->webhooks()->list();

// Get a specific webhook
$wh = $client->webhooks()->get('whk_xxx');

// Update a webhook
$client->webhooks()->update('whk_xxx', [
    'url' => 'https://new-endpoint.example.com/webhook',
    'events' => ['message.delivered', 'message.failed', 'message.sent']
]);

// Test a webhook
$result = $client->webhooks()->test('whk_xxx');

// Rotate webhook secret
$rotation = $client->webhooks()->rotateSecret('whk_xxx');

// Delete a webhook
$client->webhooks()->delete('whk_xxx');

// List available webhook event types
$eventTypes = $client->webhooks()->listEventTypes();
foreach ($eventTypes as $eventType) {
    echo "Event: {$eventType}\n";
}
```

## Account & Credits

```php
// Get account information
$account = $client->account()->get();
echo $account->email;

// Check credit balance
$credits = $client->account()->getCredits();
echo "Available: {$credits->availableBalance} credits";
echo "Reserved: {$credits->reservedBalance} credits";
echo "Total: {$credits->balance} credits";

// View credit transaction history
$transactions = $client->account()->getCreditTransactions();
foreach ($transactions as $tx) {
    echo "{$tx->type}: {$tx->amount} credits - {$tx->description}\n";
}

// List API keys
$keys = $client->account()->listApiKeys();
foreach ($keys as $key) {
    echo "{$key->name}: {$key->prefix}*** ({$key->type})\n";
}

// Get a specific API key
$key = $client->account()->getApiKey('key_xxx');

// Get API key usage stats
$usage = $client->account()->getApiKeyUsage('key_xxx');
echo "Messages sent: {$usage->messagesSent}";

// Create a new API key
$newKey = $client->account()->createApiKey([
    'name' => 'Production Key',
    'type' => 'live',
    'scopes' => ['sms:send', 'sms:read']
]);
echo "New key: {$newKey->key}"; // Only shown once!

// Revoke an API key
$client->account()->revokeApiKey('key_xxx');
```

## Error Handling

```php
use Sendly\Exceptions\AuthenticationException;
use Sendly\Exceptions\RateLimitException;
use Sendly\Exceptions\InsufficientCreditsException;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\NotFoundException;
use Sendly\Exceptions\NetworkException;
use Sendly\Exceptions\SendlyException;

try {
    $message = $client->messages()->send('+15551234567', 'Hello!');
} catch (AuthenticationException $e) {
    // Invalid API key
} catch (RateLimitException $e) {
    // Rate limit exceeded
    echo "Retry after: " . $e->getRetryAfter() . " seconds";
} catch (InsufficientCreditsException $e) {
    // Add more credits
} catch (ValidationException $e) {
    // Invalid request
    print_r($e->getDetails());
} catch (NotFoundException $e) {
    // Resource not found
} catch (NetworkException $e) {
    // Network error
} catch (SendlyException $e) {
    // Other error
    echo $e->getMessage();
    echo $e->getErrorCode();
}
```

## Message Object

```php
$message->id;           // Unique identifier
$message->to;           // Recipient phone number
$message->text;         // Message content
$message->status;       // queued, sending, sent, delivered, failed
$message->creditsUsed;  // Credits consumed
$message->createdAt;    // DateTimeImmutable
$message->updatedAt;    // DateTimeImmutable
$message->deliveredAt;  // DateTimeImmutable|null
$message->errorCode;    // string|null
$message->errorMessage; // string|null

// Helper methods
$message->isDelivered(); // bool
$message->isFailed();    // bool
$message->isPending();   // bool

// Convert to array
$message->toArray();
```

## Message Status

| Status | Description |
|--------|-------------|
| `queued` | Message is queued for delivery |
| `sending` | Message is being sent |
| `sent` | Message was sent to carrier |
| `delivered` | Message was delivered |
| `failed` | Message delivery failed |

## Pricing Tiers

| Tier | Countries | Credits per SMS |
|------|-----------|-----------------|
| Domestic | US, CA | 1 |
| Tier 1 | GB, PL, IN, etc. | 8 |
| Tier 2 | FR, JP, AU, etc. | 12 |
| Tier 3 | DE, IT, MX, etc. | 16 |

## Sandbox Testing

Use test API keys (`sk_test_v1_xxx`) with these test numbers:

| Number | Behavior |
|--------|----------|
| +15005550000 | Success (instant) |
| +15005550001 | Fails: invalid_number |
| +15005550002 | Fails: unroutable_destination |
| +15005550003 | Fails: queue_full |
| +15005550004 | Fails: rate_limit_exceeded |
| +15005550006 | Fails: carrier_violation |

## License

MIT
