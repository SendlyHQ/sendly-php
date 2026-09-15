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

`maxRetries` (default 3) applies to connection failures, timeouts and 5xx
responses, with exponential backoff between attempts. A 4xx response throws
straight away.

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

## Idempotency

POSTs carry an automatically generated `Idempotency-Key`, reused across the
SDK's own retries, so a retry of a request that already reached the API returns
the original result instead of sending and charging again. Pass your own key
(1-255 printable ASCII characters) when the guarantee needs to outlive the
process, such as a job queue that re-runs after a crash: repeating a request
with the same key within 24 hours returns the original response instead of
executing again. `sendBatch()` sends no automatic key, because the API already
deduplicates identical batches by their contents.

```php
$message = $client->messages()->send(
    '+15551234567',
    'Your order has shipped!',
    idempotencyKey: 'order-4821-shipped'
);
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

## WhatsApp

Connect a number you own to WhatsApp, create Meta-reviewed message templates,
check 24-hour conversation windows, and send WhatsApp messages by passing
`'channel' => 'whatsapp'` to `messages()->send()`.

Connecting a number is a one-time $19 setup (no monthly fee) and always ends
with a human step: the signup returns a `connectUrl` that a person must open in
a browser and log in with Facebook to link their WhatsApp Business Account.
Free-form text and media only deliver inside an open 24-hour window (the
recipient messaged you in the last 24h); an approved template works anytime.

> **Note:** The WhatsApp channel is being rolled out gradually and is not yet
> generally available; until it is enabled for your account the endpoints read
> as absent and calls throw `NotFoundException` (HTTP 404). WhatsApp writes
> (signup, templates, sends) require a live API key.

```php
// 1. Connect a number ($19 one-time). A human must finish the connect URL.
$signup = $client->whatsapp()->signup->create('+15559876543');
echo $signup['connectUrl']; // hand this to a person to complete in a browser

// Poll until active
$status = $client->whatsapp()->signup->get($signup['id']);
echo $status['status']; // "initiated" -> "registering" -> "active"

// List connected senders
$result = $client->whatsapp()->senders->list();
foreach ($result['senders'] as $s) {
    echo "{$s['phoneNumber']} ({$s['displayName']}) — {$s['status']}\n";
}

// Read and update a sender's business profile (what recipients see when
// they open your details in WhatsApp)
$profile = $client->whatsapp()->senders->getProfile('+15559876543');
echo $profile['displayName'];
echo $profile['about'];

$client->whatsapp()->senders->updateProfile('+15559876543', [
    'about' => 'Fresh roasted coffee, delivered.',   // max 139 chars
    'description' => 'Small-batch roaster shipping nationwide.', // max 512
    'website' => 'https://acme.example',
]);

// 2. Create a template (Meta reviews it, usually 24-48h)
$template = $client->whatsapp()->templates->create([
    'sender' => '+15559876543',
    'name' => 'order_shipped',
    'language' => 'en_US',
    'category' => 'UTILITY',
    'body' => 'Hi {{1}}, your order {{2}} has shipped!',
    'examples' => ['1' => 'Sam', '2' => '#4821'],
]);
echo $template['status']; // "PENDING"

// Fix a rejected template by editing it (template names are locked for
// ~30 days after deletion, so edit instead of delete + re-create)
$client->whatsapp()->templates->update($template['id'], [
    'body' => 'Hi {{1}}, your order {{2}} is on its way!',
    'examples' => ['1' => 'Sam', '2' => '#4821'],
]);

// List and delete templates
$result = $client->whatsapp()->templates->list();
$client->whatsapp()->templates->delete('wat_xxx');

// 3. Check the 24-hour window, then send
$window = $client->whatsapp()->window('+15559876543', '+15551234567');

if ($window['open']) {
    // Free-form text inside the window
    $message = $client->messages()->send([
        'channel' => 'whatsapp',
        'to' => '+15551234567',
        'from' => '+15559876543',
        'text' => 'Your table is ready!',
    ]);
} else {
    // An approved template works regardless of the window
    $message = $client->messages()->send([
        'channel' => 'whatsapp',
        'to' => '+15551234567',
        'from' => '+15559876543',
        'template' => [
            'name' => 'order_shipped',
            'language' => 'en_US',
            'variables' => ['1' => 'Acme Inc', '2' => '#4821'],
        ],
    ]);
}

echo $message['id'];
echo $message['whatsapp']['kind']; // "text" or "template"
echo $message['creditsUsed'];      // priced by destination country + category

// Media with a caption (inside the window; exactly one media URL per message)
$client->messages()->send([
    'channel' => 'whatsapp',
    'to' => '+15551234567',
    'from' => '+15559876543',
    'mediaUrls' => ['https://example.com/receipt.pdf'],
    'text' => 'Here is your receipt.',
]);
```

## RCS

Send branded rich messages — text with suggested replies and actions, or rich
cards with an image and buttons — through your workspace's RCS agent by
passing `'channel' => 'rcs'` to `messages()->send()`. Delivery is
per-recipient: not every device or network supports RCS. Text sends fall back
to plain SMS automatically (billed as SMS) unless you disable the fallback;
rich cards have no SMS form and only deliver to RCS-capable recipients.

> **Note:** The RCS channel is being rolled out gradually and is not yet
> generally available; until it is enabled for your account the endpoints read
> as absent and calls throw `NotFoundException` (HTTP 404; the registration
> endpoints answer `rcs_not_enabled`). RCS sends and capability checks require
> a live API key. Registration reads need an API key with the `rcs:read` scope
> and writes the `rcs:write` scope.

### Register a brand and agent

Registration is self-serve, from the RCS section of your dashboard or over the
API, and open to US businesses for now. Draft a brand (your business identity)
and an agent (the sender recipients see), then submit them. Sendly reviews the
registration first, then the carrier network verifies your business and
reviews the agent. Once the agent reaches `testing` you invite handsets, send
to them, file the campaign details, and request launch.

Logo, hero and call-to-action media must already be hosted at public
`https://` URLs. File upload is dashboard-only.

```php
use Sendly\Resources\RcsCustomerStage;

// Prefill from what Sendly already holds (your 10DLC brand or toll-free verification)
$dossier = $client->rcs()->dossier->get();

$brand = $client->rcs()->brands->create(array_merge($dossier['brand'], [
    'displayName' => 'Acme Coffee',
    'legalEntityType' => 'LIMITED_LIABILITY_COMPANY',
    'organizationType' => 'PRIVATE_PROFIT',
    'websiteUrl' => 'https://acme.example',
    'ein' => '12-3456789',
    'address' => ['line1' => '1 Market St', 'city' => 'San Francisco', 'state' => 'CA', 'postalCode' => '94105', 'countryCode' => 'US'],
    'contact' => ['firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'jane@acme.example', 'phoneNumber' => '+15551234567'],
]))['brand'];

$agent = $client->rcs()->agents->create([
    'brandId' => $brand['id'],
    'displayName' => 'Acme Coffee',
    'useCase' => 'MULTI_USE',
    'basics' => [
        'description' => 'Order updates and offers from Acme Coffee.',
        'logoUrl' => 'https://acme.example/rcs/logo.png',
        'heroUrl' => 'https://acme.example/rcs/hero.png',
        'brandColor' => '#6B4F3A',
        'privacyPolicyUrl' => 'https://acme.example/privacy',
        'termsAndConditionsUrl' => 'https://acme.example/terms',
        'phoneNumber' => ['number' => '+15551234567', 'label' => 'Call us'],
        'website' => ['url' => 'https://acme.example', 'label' => 'Visit us'],
    ],
])['agent'];

// Submit both for review (pass your own idempotency key so a retried job
// does not file the request twice), then watch the stage
$client->rcs()->agents->submit($agent['id'], 'acme-rcs-submit-1');

$registration = $client->rcs()->registration->get();
echo $registration['stage']; // in_review, changes_requested, brand_verification, agent_review, testing, ...
if ($registration['stage'] === RcsCustomerStage::CHANGES_REQUESTED) {
    echo $registration['agent']['reviewNote'];
    $client->rcs()->agents->update($agent['id'], ['basics' => ['description' => 'Order updates from Acme Coffee.']]);
    $client->rcs()->agents->submit($agent['id']);
}

// In testing: invite handsets (the list replaces the previous one), send to
// them, then file the campaign details and request launch
$client->rcs()->agents->setTestDevices($agent['id'], [
    '+15551234567',
    ['phoneNumber' => '+15559876543', 'label' => 'QA phone'],
]);

$client->rcs()->agents->update($agent['id'], [
    'campaign' => [
        'agentOverview' => 'Sends order confirmations, pickup alerts and occasional offers to opted-in customers.',
        'interactions' => [
            ['interactionType' => 'TRANSACTIONAL_UPDATES', 'description' => 'Order and pickup status'],
        ],
        'messageExamples' => [
            'Your order #4821 is ready for pickup.',
            'Thanks for your order! We will text you when it is ready.',
            'Reply STOP to opt out at any time.',
        ],
        'consentSettings' => [
            'optInMethods' => [['methodType' => 'WEBSITE', 'description' => 'Checkbox at checkout']],
            'optInMessage' => 'Acme Coffee: you are opted in to order updates. Reply STOP to opt out.',
            'helpResponse' => 'Acme Coffee: reply STOP to opt out or call +1 555 123 4567.',
            'optOutResponse' => 'Acme Coffee: you are opted out and will receive no more messages.',
        ],
    ],
]);
$client->rcs()->agents->requestLaunch($agent['id'], ['testUrl' => 'https://acme.example/rcs-test-notes']);

// Field-level problems come back as ValidationException with the API's
// errors list; review locks and not-ready states as SendlyException
try {
    $client->rcs()->agents->submit($agent['id']);
} catch (\Sendly\Exceptions\ValidationException $e) {
    foreach ($e->getDetails() ?? [] as $issue) {
        echo "{$issue['path']}: {$issue['message']}\n"; // e.g. brand.ein: Enter a 9-digit EIN
    }
} catch (\Sendly\Exceptions\SendlyException $e) {
    echo $e->getApiErrorCode(); // rcs_field_locked, rcs_brand_not_verified, ...
}
```

### Send over RCS

```php
// Discover your RCS agents ('testing' reaches invited test devices only;
// 'approved' reaches everyone). Pass 'agentId' on sends and capability
// checks when your workspace has more than one agent.
$result = $client->rcs()->agents->list();
foreach ($result['agents'] as $a) {
    echo "{$a['name']} — {$a['status']}" . ($a['sendable'] ? ' (sendable)' : '') . "\n";
}

// Pre-flight: can this recipient receive RCS?
$cap = $client->rcs()->capability('+15551234567');
echo $cap['capable'] ? 'RCS' : 'would fall back to SMS';

// Text with suggested replies and actions
$message = $client->messages()->send([
    'channel' => 'rcs',
    'to' => '+15551234567',
    'text' => 'Your order has shipped! Want live updates?',
    'suggestions' => [
        ['reply' => ['text' => 'Yes, notify me', 'postbackData' => 'notify_yes']],
        ['action' => ['text' => 'Track order', 'postbackData' => 'track', 'url' => 'https://acme.example/orders/4821']],
    ],
]);

// The response discloses which channel delivered
echo $message['channel']; // "rcs", or "sms" when it fell back
if (($message['fellBackTo'] ?? null) === 'sms') {
    // Delivered as plain SMS (billed as SMS). Suggestions have no SMS form
    // and were dropped ($message['rcs']['suggestionsDropped'] is true).
} else {
    echo $message['rcs']['kind'];      // "text" or "card"
    echo $message['rcs']['agentName']; // the brand name recipients see
}

// Rich card (RCS-capable recipients only — cards have no SMS form)
$client->messages()->send([
    'channel' => 'rcs',
    'to' => '+15551234567',
    'card' => [
        'title' => 'Spring collection',
        'description' => 'New arrivals are in - take a look.',
        'mediaUrl' => 'https://example.com/spring.jpg', // public JPEG/PNG/GIF
        'orientation' => 'vertical', // or 'horizontal'
        'suggestions' => [
            ['action' => ['text' => 'Shop now', 'postbackData' => 'shop', 'url' => 'https://acme.example/spring']],
        ],
    ],
]);

// Opt out of the SMS fallback — the send fails with a 422
// (rcs_not_supported_for_recipient) when the recipient can't receive RCS
$client->messages()->send([
    'channel' => 'rcs',
    'to' => '+15551234567',
    'text' => 'RCS or nothing',
    'fallbackToSms' => false,
]);
```

## Voice Calls

Place a phone call that one of your workspace's AI agents handles, follow it
while it rings and runs, end it early, and fetch the recording afterwards.
Agents, which numbers take calls and how they answer, and emergency addresses
are set up from code with [`$client->voice`](#configure-voice) or in the
dashboard under Calls; `$client->voice->numbers->list()` reports
`voiceEnabled`, `voiceMode` and the emergency address on each number so you
can pick one to call from.

> **Note:** Calls are prepaid from your credit balance per started minute:
> 2 credits a minute outbound plus 8 credits a minute while an AI agent is on
> the call (10 credits a minute in total), US and Canada only, and unanswered
> calls cost nothing. The `from` number must be voice-enabled and have an
> emergency address registered. Voice is enabled workspace by
> workspace; until it is on for your account the endpoints throw
> `NotFoundException` (`voice_not_enabled`). Reads need the `calls:read`
> scope, writes `calls:write` and a live key.

```php
use Sendly\Resources\CallStatus;
use Sendly\Resources\CallRecordingStatus;
use Sendly\Exceptions\InsufficientCreditsException;
use Sendly\Exceptions\SendlyException;

// Place a call. The agent speaks first; `context` steers what it says on
// this call only, and `metadata` comes back on every read and webhook.
try {
    $call = $client->calls()->create([
        'to' => '+15555550123',
        'agentId' => '3c4d5e6f-7081-4293-a4b5-c6d7e8f90a1b',
        'from' => '+15555550188', // optional when only one number is voice-enabled
        'context' => 'You are calling Jordan to confirm the 3pm appointment on Tuesday.',
        'metadata' => ['crmId' => 'lead_8812'],
    ]);
    echo $call['id'];     // "6f1c2d3e-..."
    echo $call['status']; // "ringing"
} catch (InsufficientCreditsException $e) {
    // Top up: the balance cannot cover one minute at the agent rate
} catch (SendlyException $e) {
    // 428 e911_required: register an emergency address for the number first
    // 409 lines_busy: every line is in use, retry in a moment
    echo $e->getCode() . ' ' . $e->getApiErrorCode();
}

// Follow the call. Agent-handled calls carry a transcript once fetched by id.
$call = $client->calls()->get($call['id']);
if ($call['status'] === CallStatus::COMPLETED) {
    echo "{$call['durationSecs']}s, {$call['creditsCharged']} credits, ended: {$call['hangupClass']}\n";
    foreach ($call['transcript'] ?? [] as $line) {
        echo "[{$line['speaker']}] {$line['text']}\n";
    }
}

// List calls, newest first, with filters and pagination
$result = $client->calls()->list([
    'status' => CallStatus::COMPLETED,
    'direction' => 'outbound',
    'agentId' => '3c4d5e6f-7081-4293-a4b5-c6d7e8f90a1b',
    'limit' => 20,
]);
foreach ($result['data'] as $c) {
    echo "{$c['from']} -> {$c['to']} {$c['status']}\n";
}
if ($result['pagination']['hasMore']) {
    // fetch the next page with 'offset' => $result['pagination']['offset'] + $result['pagination']['limit']
}

// End a call early. Ringing -> cancelled, active -> completed; a call that
// has already ended is returned unchanged.
$client->calls()->hangup($call['id']);

// Fetch the recording. The URL is signed and valid for five minutes.
// Agent-handled calls are stereo: the agent on the left channel, the other party on the right.
$recording = $client->calls()->recording($call['id']);
if ($recording['status'] === CallRecordingStatus::READY) {
    file_put_contents('call.ogg', file_get_contents($recording['url']));
}
```

| Method | Endpoint | Scope | Description |
| --- | --- | --- | --- |
| `calls()->create($params, $idempotencyKey = null)` | `POST /calls` | `calls:write` | Place a call handled by an AI agent. `to` and `agentId` required; `from`, `context`, `metadata` optional. |
| `calls()->list($options = [])` | `GET /calls` | `calls:read` | List calls. `limit`, `offset`, `status`, `direction`, `kind`, `agentId`, `to`, `from`. Returns `data` and `pagination` (`total`, `limit`, `offset`, `hasMore`). |
| `calls()->get($id)` | `GET /calls/{id}` | `calls:read` | One call, plus `transcript` on agent-handled calls. |
| `calls()->hangup($id, $idempotencyKey = null)` | `POST /calls/{id}/hangup` | `calls:write` | End a ringing or active call. |
| `calls()->recording($id)` | `GET /calls/{id}/recording` | `calls:read` | `status` (`none`, `recording`, `ready`, `failed`), `url` and `expiresAt` (set when `ready`), `contentType` (`audio/ogg`). |

`CallStatus`, `CallDirection`, `CallKind`, `CallHandledBy`, `CallBilling`,
`CallRecordingStatus` and `CallErrorCode` in `Sendly\Resources` hold the string
values the endpoints use. A call's `billing` is `metered` while a phone call is
charged per minute, `settled` once it has ended, and `unbilled` for calls that
were never charged (browser-to-browser calls between teammates are free).
`hangupClass` says why a call ended: `normal`, `caller_hung_up`,
`callee_hung_up` and `agent_agent_hangup` are ordinary endings; `ring_timeout`,
`callee_busy`, `callee_declined` and `caller_cancelled` mean it never
connected; `max_duration` (60 minutes) and `credits_exhausted` mean the
platform cut it short.

The `call.started`, `call.completed` and `call.recording.ready` webhooks carry
the call at `$event->object` in snake_case (`handled_by`, `duration_secs`,
`credits_charged`, `hangup_class`, `recording_status`, `billing`, `metadata`).

### Configure voice

Set up everything a call depends on from code: switch voice on for a number
and choose how it answers, register its emergency address, and create the AI
agents that talk. `$client->voice` uses the same `calls:read` and
`calls:write` scopes, and writes need a live key. In a team workspace,
changing a number or its emergency address needs a role that can change
settings, and managing agents needs a role that can manage API keys (each
agent holds its own scoped sending key); otherwise the API responds
`403 forbidden`. Address a number by its id or its E.164 phone number.

```php
use Sendly\Resources\VoiceMode;
use Sendly\Resources\CallErrorCode;
use Sendly\Exceptions\SendlyException;
use Sendly\Exceptions\ValidationException;

// Pick a voice, then create an agent
foreach ($client->voice->voices->list()['data'] as $voice) {
    echo "{$voice['id']}: {$voice['label']}\n"; // "ashley: Ashley (US, warm)"
}

$agent = $client->voice->agents->create([
    'name' => 'Front desk',
    'voice' => 'ashley',
    'greeting' => 'Thanks for calling Acme, how can I help?',
    'instructions' => 'Answer questions about opening hours and take a message for anything else.',
    'tools' => ['sendSms' => true],
]);
echo $agent['id'];                          // "3c4d5e6f-..."
var_dump($agent['canSendSms']);             // true

// Change only what you pass; tools keys you leave out keep their values
$client->voice->agents->update($agent['id'], [
    'greeting' => 'Thanks for calling Acme. How can I help today?',
]);

// Register the emergency address: required before a US or Canadian number
// can place calls, and $1.50 a month
try {
    $number = $client->voice->numbers->registerEmergencyAddress('+15555550188', [
        'street' => '500 Example Ave',
        'unit' => 'Suite 2',
        'city' => 'Austin',
        'state' => 'TX',
        'zip' => '78701',
    ]);
    echo $number['emergencyAddress']['status']; // "provisioning", then "active"
} catch (ValidationException $e) {
    // 422 invalid_address: the address couldn't be validated
    print_r($e->getResponseBody()['suggested'] ?? null); // a corrected address, when one was found
}

// Switch voice on and have the agent answer real callers
$number = $client->voice->numbers->update('+15555550188', [
    'voiceEnabled' => true,
    'voiceMode' => VoiceMode::AGENT,
    'agentId' => $agent['id'],
]);
echo $number['voiceMode'];                  // "agent"
print_r($number['ratePerMinute']);          // ['inbound' => 2, 'outbound' => 2, 'agent' => 10]

// Ring the team in the dashboard instead, or switch voice off
$client->voice->numbers->update($number['id'], ['voiceMode' => VoiceMode::RING_DASHBOARD]);
$client->voice->numbers->update($number['id'], ['voiceEnabled' => false]);

// Every number and agent in the workspace
foreach ($client->voice->numbers->list()['data'] as $n) {
    echo "{$n['phoneNumber']} {$n['voiceMode']}\n";
}
$agents = $client->voice->agents->list()['data'];

// Delete an agent once no number answers with it; its sending key is revoked
try {
    $client->voice->agents->delete($agent['id']);
} catch (SendlyException $e) {
    if ($e->getApiErrorCode() === CallErrorCode::AGENT_IN_USE) {
        print_r($e->getResponseBody()['numbers']); // ['+15555550188']
    }
}
```

| Method | Endpoint | Scope | Description |
| --- | --- | --- | --- |
| `voice->numbers->list()` | `GET /voice/numbers` | `calls:read` | Active numbers under `data`, each with `voiceEnabled`, `voiceMode`, `agentId`, `emergencyAddress` and `ratePerMinute`. |
| `voice->numbers->get($number)` | `GET /voice/numbers/{number}` | `calls:read` | One number, by id or E.164 phone number. |
| `voice->numbers->update($number, $params)` | `PATCH /voice/numbers/{number}` | `calls:write` | `voiceEnabled`, `voiceMode` (`none`, `ring_dashboard`, `agent`), `agentId` (`null` clears it). |
| `voice->numbers->registerEmergencyAddress($number, $params, $idempotencyKey = null)` | `POST /voice/numbers/{number}/emergency-address` | `calls:write` | `street`, `city`, `state`, `zip` required; `unit` and `country` (`US`, the default, or `CA`) optional. |
| `voice->agents->list()` | `GET /voice/agents` | `calls:read` | Agents under `data`, with `callsHandled` and `avgDurationSecs`. |
| `voice->agents->create($params, $idempotencyKey = null)` | `POST /voice/agents` | `calls:write` | `name` required; `enabled`, `voice`, `language`, `greeting`, `instructions`, `tools` (`sendSms`, `transferTo`) optional. |
| `voice->agents->get($id)` | `GET /voice/agents/{id}` | `calls:read` | One agent. |
| `voice->agents->update($id, $params)` | `PATCH /voice/agents/{id}` | `calls:write` | Any subset of the create fields. |
| `voice->agents->delete($id)` | `DELETE /voice/agents/{id}` | `calls:write` | Delete an agent and revoke its sending key. Returns `id`, `object` and `deleted`. |
| `voice->voices->list()` | `GET /voice/voices` | `calls:read` | Voices (`id`, `label`, `language`) under `data`. |

Every resource is also reachable method-style: `$client->voice()->numbers()->list()`.
A `voiceMode` on its own is enough: `ring_dashboard` or `agent` switches voice
on (and can be refused like any switch-on) and `none` switches it off. When
`voiceEnabled` is sent too it wins: `false` switches voice off, and `true` with
`none` rings the dashboard. A
workspace can have up to 20 agents, and an unknown voice id falls back to the
default voice. Agents can't transfer calls yet: with `tools.transferTo` set, a
caller who asks for a person is told the message will be passed on and the
agent takes their name and number.

Refusals: `number_not_found` and `agent_not_found` (404, `NotFoundException`);
`invalid_request`, `invalid_voice_mode`, `agent_required`, `invalid_address`
and `e911_not_applicable` (400, `ValidationException`); `invalid_address`
(422, `ValidationException`, with a corrected address or null under
`suggested` in `getResponseBody()`); `agent_disabled` (409, switch the agent
on first), `agent_limit` (409) and `agent_in_use` (409, the numbers the agent
still answers under `numbers` in `getResponseBody()`); `voice_attach_failed`
(502, try again); `carrier_refused` (502, try again, unless the message says
the number couldn't be found for emergency registration: contact support); and
`voice_unavailable` (503). The
409, 502 and 503 refusals arrive as `SendlyException` with `getCode()` and
`getApiErrorCode()` set, and `CallErrorCode` holds every code.

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

### Receiving webhook events

`Webhooks::parseEvent()` verifies the signature and returns a `WebhookEvent`.

```php
use Sendly\Webhooks;
use Sendly\WebhookVerificationData;
use Sendly\Exceptions\WebhookSignatureException;

try {
    $event = Webhooks::parseEvent(
        $rawRequestBody,
        $_SERVER['HTTP_X_SENDLY_SIGNATURE'],
        $webhookSecret,
        $_SERVER['HTTP_X_SENDLY_TIMESTAMP'] ?? null
    );
} catch (WebhookSignatureException $e) {
    http_response_code(401);
    return;
}
```

`$event->object` is `data.object` exactly as it arrived, for every event type.
Keys are verbatim, nothing is defaulted, and a JSON `null` stays `null`:

```php
match ($event->type) {
    'rcs_agent.live'             => activateAgent($event->object['agent_id']),
    'whatsapp_template.approved' => touchTemplate($event->object['id'], $event->object['updatedAt']),
    // call.* numbers are legitimately null for in-app calls
    'call.completed'             => logCall($event->object['from'], $event->object['to']),
    // the contact is `id`; the message that flagged it is `message_id`
    'contact.auto_flagged'       => quarantine($event->object['id'], $event->object['message_id']),
    default                      => null,
};
```

`$event->data` is the *message* view of `data.object`. It is populated for
`message.*` events and is **null for every other event type**, because their
payload is not a message. Check it before reading through it:

```php
if ($event->data !== null) {
    echo "{$event->data->id} is {$event->data->status}";
}
```

Every field on `$event->data` is nullable and reflects what the payload
carried. A field the event did not send is `null`, never a stand-in such as
`''`, `1` or `'outbound'`.

Typed views for other payloads come from `objectAs()`, which builds a class
through its static `fromArray(array $data)` when it has one and otherwise
through a constructor taking the array:

```php
$verification = $event->objectAs(WebhookVerificationData::class);
// or, for verification.* events:
$verification = $event->verification();

echo $verification?->phone;
```

Other helpers: `$event->get('key', $default)` reads one key off
`data.object` (`$default` applies only when the key is absent, so a `null` the
server sent stays `null`), and `WebhookEvent::isMessageEvent($type)` tells you
whether an event type carries a message.

### Handling a lifecycle event

Lifecycle events — `rcs_*`, `whatsapp_*`, `call.*`, `brand.*`, `campaign.*`,
`assignment.*`, `number.*`, `port*`, `contact*` — carry their own object at
`data.object`, not a message. Read them off `$event->object`; `$event->data` is
`null` for all of them. A complete endpoint:

```php
<?php
// public/sendly-webhook.php

require __DIR__ . '/../vendor/autoload.php';

use Sendly\Webhooks;
use Sendly\Exceptions\WebhookSignatureException;

$raw = file_get_contents('php://input') ?: '';

try {
    $event = Webhooks::parseEvent(
        $raw,
        $_SERVER['HTTP_X_SENDLY_SIGNATURE'] ?? '',
        getenv('SENDLY_WEBHOOK_SECRET') ?: '',
        $_SERVER['HTTP_X_SENDLY_TIMESTAMP'] ?? null
    );
} catch (WebhookSignatureException $e) {
    http_response_code(401);
    exit;
}

switch ($event->type) {
    // Lifecycle: data.object is an RCS agent.
    // {"agent_id": "...", "name": "...", "stage": "live", "organization_id": "..."}
    case 'rcs_agent.live':
        $agentId = $event->object['agent_id']; // verbatim, nothing defaulted
        $stage   = $event->get('stage');       // null when the key is absent
        error_log("RCS agent {$agentId} reached {$stage}");
        // $event->data is null here. An agent is not a message.
        break;

    // Lifecycle: a call. from/to are legitimately null for in-app calls.
    case 'call.completed':
        error_log(sprintf(
            'call %s ran %d s',
            $event->object['id'],
            $event->object['duration_secs'] ?? 0
        ));
        break;

    // Message event: the typed view is populated, so use it.
    case 'message.delivered':
        if ($event->data !== null) {
            error_log("{$event->data->id} delivered to {$event->data->to}");
        }
        break;
}

http_response_code(200);
```

Reaching through `$event->data` on one of these events is the mistake this release
makes visible: on 3.x it handed back `''`, `0`, `1` or `'outbound'` and raised
nothing, so `$event->data->id` on `rcs_agent.live` silently acted on an empty id.
`$event->data` is `null` there now.

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
    echo $e->getApiErrorCode(); // the API's error code, e.g. "rcs_field_locked"
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
