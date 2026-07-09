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

// Send from one of your owned numbers (or an alphanumeric sender ID).
// Omit `from` to use your default sender.
$message = $client->messages()->send([
    'to' => '+15551234567',
    'text' => 'Hello from our team!',
    'from' => '+447111111111',
]);

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

### Group MMS

Send one MMS to 2-8 recipients (US/Canada only). Everyone shares a single
thread and replies fan out to all participants. Group messaging is an A2P
10DLC capability — the sending number must be an MMS-enabled, 10DLC-registered
number you own. Requires the `group_mms` feature (and `enable_mms` for media)
to be enabled for your account.

```php
$group = $client->messages()->sendGroup([
    'to' => ['+14155551234', '+14155555678'],
    'text' => 'Hey team - quick sync at noon?',
    // 'from' => '+15125550100',           // optional; omit to use your default sender
    // 'mediaUrls' => ['https://.../a.jpg'], // optional; text or mediaUrls required
    // 'messageType' => 'transactional',     // default; use 'marketing' for quiet hours
]);

echo $group['id'];                // "msg_xxx"
echo $group['status'];            // "sent" (or "delivered" when simulated)
echo $group['group_message_id'];  // "grp_xxx" (present on live sends)
```

### AI Message Enhancement

Rewrite a draft into a single, polished SMS segment (≤160 characters) and get a
short explanation of what changed. Pass `messageType` to steer the rewrite; with
no `text` it generates a suitable message for that type instead. At least one of
`text` or `messageType` is required. Requires the `ai_classification` feature;
when AI is unavailable the original text is returned with an empty explanation.

```php
$result = $client->messages()->enhance(
    'hey come check out our sale this weekend',
    'marketing'
);

echo $result['enhanced'];     // polished, ≤160-char rewrite
echo $result['explanation'];  // what changed and why
echo $result['model'] ?? '';  // model used, when available
```

## Numbers

List the phone numbers on your account, inspect one, make a number your default
sender or keep a number scheduled for release, and release a number.

```php
// List your numbers
$result = $client->numbers()->list();
foreach ($result['numbers'] as $n) {
    echo "{$n['phoneNumber']} — {$n['status']} ({$n['phoneNumberType']})\n";
}

// Get a single number (includes `isDefault`)
$number = $client->numbers()->get('num_abc123');
echo $number['phoneNumber']; // "+15551234567"
echo $number['isDefault'] ? 'default sender' : 'not default';

// Make a number your workspace's default sender (must be active)
$client->numbers()->update('num_abc123', ['isDefault' => true]);

// "Keep this number": undo a scheduled period-end release
$client->numbers()->update('num_abc123', ['pendingCancellation' => false]);

// Release a number. A live paid purchase is cancelled at period end;
// anything else is released immediately.
$result = $client->numbers()->release('num_abc123');
if ($result['scheduled'] ?? false) {
    echo "Releases at {$result['scheduledReleaseAt']}";
} else {
    echo 'Released';
}
```

## Short Links (URL Shortening)

Mint branded short links for a destination URL, list them with click analytics,
and disable (kill) an individual link. Branded, owned-domain short links improve
deliverability — carriers filter public shorteners — and give you click data.

> **Note:** URL shortening is gated behind the `url_shortener` rollout flag and
> is not yet generally available; until the flag is enabled for your account the
> endpoints read as absent and calls throw `NotFoundException` (HTTP 404).

```php
// Shorten a URL
$link = $client->links()->create('https://example.com/spring-sale?utm_source=sms');
echo $link['code'];      // "Ab3xY7"
echo $link['shortUrl'];  // "https://sendly.live/l/Ab3xY7"

// List your links with click counts
$result = $client->links()->list(['limit' => 20]);
foreach ($result['links'] as $l) {
    echo "{$l['shortUrl']} -> {$l['destinationUrl']} ({$l['clickCount']} clicks)\n";
}

// Kill a link (its redirect returns 404 until re-enabled)
$client->links()->disable('Ab3xY7');

// Re-enable it
$client->links()->enable('Ab3xY7');
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
$newKey = $client->account()->createApiKey('Production Key', [
    'expiresAt' => '2027-01-01T00:00:00Z', // optional
]);
echo "New key: {$newKey['key']}"; // Only shown once!

// Rotate an API key (old key stays valid for a grace period, default 24h)
$rotation = $client->account()->rotateApiKey('key_xxx', [
    'gracePeriodHours' => 48, // optional; 24-168 inclusive
]);
echo "New key: {$rotation['newKey']['key']}"; // Only shown once!
echo $rotation['message'];                     // "Old key will expire in 48 hours"

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
| Domestic | US, CA | 2 |
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

## Enterprise

The Enterprise API lets you programmatically manage workspaces, verification, credits, and API keys for multi-tenant platforms. Requires an enterprise master key (`sk_live_v1_master_*`).

### Quick Provision

Create a fully configured workspace in a single call:

```php
use Sendly\Sendly;

$client = new Sendly('sk_live_v1_master_YOUR_KEY');

$result = $client->enterprise->provision([
    'name' => 'Acme Insurance - Austin',
    'sourceWorkspaceId' => 'ws_verified',
    'creditAmount' => 5000,
    'creditSourceWorkspaceId' => 'SOURCE_WORKSPACE_ID',
    'keyName' => 'Production',
    'keyType' => 'live',
    'generateOptInPage' => true,
]);

echo $result['workspace']['id'];
echo $result['key']['key'];
```

Three provisioning modes:

| Mode | Params | Description |
|------|--------|-------------|
| **Inherit** | `sourceWorkspaceId` | Shares toll-free number from verified workspace |
| **Inherit + New Number** | `sourceWorkspaceId` + `inheritWithNewNumber => true` | Copies business info, purchases new number |
| **Fresh** | `verification => [...]` | Full business details, new number + carrier approval |

### Workspace Management

```php
$ws = $client->enterprise->workspaces->create('Acme Insurance');
$list = $client->enterprise->workspaces->list();
$detail = $client->enterprise->workspaces->get('ws_xxx');
$client->enterprise->workspaces->delete('ws_xxx');
```

### Credits & API Keys

```php
$client->enterprise->workspaces->transferCredits('ws_dest', [
    'sourceWorkspaceId' => 'ws_source',
    'amount' => 5000,
]);

$key = $client->enterprise->workspaces->createKey('ws_xxx', [
    'name' => 'Production',
    'type' => 'live',
]);
echo $key['key'];

$client->enterprise->workspaces->revokeKey('ws_xxx', 'key_abc');
```

### Webhooks & Analytics

```php
$client->enterprise->webhooks->set('https://yourapp.com/webhooks');
$overview = $client->enterprise->analytics->overview();
$messages = $client->enterprise->analytics->messages('30d');
$delivery = $client->enterprise->analytics->delivery();
```

Full enterprise docs: [sendly.live/docs/enterprise](https://sendly.live/docs/enterprise)

---

## License

MIT
