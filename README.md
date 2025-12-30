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
    'baseUrl' => 'https://api.sendly.live/v1',
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
    ['messageType' => 'transactional']
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
