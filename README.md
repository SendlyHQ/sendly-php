<p align="center">
  <img src="https://raw.githubusercontent.com/SendlyHQ/sendly-php/main/.github/header.svg" alt="Sendly PHP SDK" />
</p>

<p align="center">
  <a href="https://packagist.org/packages/sendly/sendly-php"><img src="https://img.shields.io/packagist/v/sendly/sendly-php.svg?style=flat-square" alt="Packagist" /></a>
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
    '+12125550123',
    'Hello from Sendly!'
);

echo $message->id;     // "4a7c1e2f-9b3d-4c8a-91f2-7d5e6a0b3c19"
echo $message->status; // "queued"
```

## Prerequisites for Live Messaging

Before sending live SMS messages, you need:

1. **Business Verification** - Complete verification in the [Sendly dashboard](https://sendly.live/dashboard)
   - **International**: Instant approval (just provide Sender ID)
   - **US/Canada**: Requires carrier approval

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
    'organization_id' => 'org_xxx',
]);
```

`maxRetries` (default 3) applies to connection failures, timeouts and 5xx
responses, with exponential backoff between attempts (1, 2, then 4 seconds).
A 4xx response throws straight away, with one exception: a 429
`too_many_concurrent_verifications` means too many first-time API key checks
were running at once from your address, so the request never ran. The client
waits its `Retry-After` (1 second) and sends it again, within the same
`maxRetries`. Every other 429 throws; see [Rate Limits](#rate-limits). Every
retry carries the same idempotency key.

`timeout` defaults to 30 seconds (the connect timeout is fixed at 10).
`organization_id` sends an `X-Organization-Id` header; it falls back to the
`SENDLY_ORG_ID` environment variable, and you can change it later with
`$client->setOrganizationId('org_yyy')`. The API ignores this header for API
keys: a key always acts in the workspace it was created in, so to work in
another workspace use a key created there.

## Messages

### Send an SMS

```php
// Marketing message (default)
$message = $client->messages()->send(
    '+12125550123',
    'Check out our new features!'
);

// Transactional message (bypasses quiet hours)
$message = $client->messages()->send(
    '+12125550123',
    'Your verification code is: 123456',
    'transactional'
);

// With custom metadata (max 4KB)
$message = $client->messages()->send(
    '+12125550123',
    'Your order #12345 has shipped!',
    null, // messageType
    ['order_id' => '12345', 'customer_id' => 'cust_abc']
);

// Send from one of your owned numbers (or an alphanumeric sender ID).
// Omit `from` to use your default sender.
$message = $client->messages()->send([
    'to' => '+12125550123',
    'text' => 'Hello from our team!',
    'from' => '+447700900123',
]);

echo $message->id;
echo $message->status;
echo $message->creditsUsed;

// A test key, a sandbox destination, or a live key the account is not yet
// set up to send with gives a simulated send: nothing reached a handset.
if ($message->isSimulated()) {
    echo $message->simulatedReason ?? 'simulated'; // the reason, for a live key
}
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
    'to' => '+12125550123',
    'limit' => 20,
    'offset' => 0,
]);

// Pagination info: total counts every matching message, not just this page
echo $messages->total;
echo $messages->hasMore ? 'more pages' : 'last page';
```

A page holds at most 100 messages; a larger `limit` is capped at 100. A test
key lists only sandbox messages and a live key only live ones.

### Get a Message

```php
$message = $client->messages()->get('4a7c1e2f-9b3d-4c8a-91f2-7d5e6a0b3c19');

echo $message->to;
echo $message->text;
echo $message->status;
echo $message->deliveredAt?->format('Y-m-d H:i:s');
```

### Scheduling Messages

```php
// Schedule a message for future delivery. Returns a plain array, not an object.
$scheduled = $client->messages()->schedule(
    '+12125550123',
    'Your appointment is tomorrow!',
    gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 hour')) // 5 minutes to 5 days ahead
);

echo $scheduled['id'];
echo $scheduled['scheduledAt'];
echo $scheduled['status'];          // "scheduled"
echo $scheduled['creditsReserved']; // reserved now, refunded if you cancel

// List scheduled messages: rows under 'data', how many under 'count'
$result = $client->messages()->listScheduled(['limit' => 20]);
foreach ($result['data'] as $msg) {
    echo "{$msg['id']}: {$msg['scheduledAt']}\n";
}

// Get a specific scheduled message
$msg = $client->messages()->getScheduled('schd_xxx');

// Cancel a scheduled message (refunds the reserved credits). Cancelling is
// refused less than a minute before the send time.
$result = $client->messages()->cancelScheduled('schd_xxx');
echo "Refunded: {$result['creditsRefunded']} credits";
```

### Batch Messages

```php
// Send up to 10,000 messages in one API call. Returns a plain array, not an object.
$batch = $client->messages()->sendBatch([
    ['to' => '+12125550123', 'text' => 'Hello User 1!'],
    ['to' => '+12125550147', 'text' => 'Hello User 2!'],
    ['to' => '+12125550155', 'text' => 'Hello User 3!'],
]);

echo $batch['batchId'];
echo "Status: {$batch['status']}";   // "processing" while it runs
echo "Total: {$batch['total']}";
echo "Sent: {$batch['sent']}, Failed: {$batch['failed']}";
echo "Credits used: {$batch['creditsUsed']}";
// Also present: 'optedOutSkipped', 'invalidSkipped', 'creditsRefunded' and
// 'messages' (per-recipient results, empty while 'processing'). 'retrying' is
// only there when the batch finished inside the request (test keys).

// Get batch status
$status = $client->messages()->getBatch($batch['batchId']);
echo "{$status['sent']}/{$status['total']} sent";

// List all batches
$batches = $client->messages()->listBatches();

// Preview batch (dry run) - validates without sending
$preview = $client->messages()->previewBatch([
    ['to' => '+12125550123', 'text' => 'Hello User 1!'],
    ['to' => '+447700900123', 'text' => 'Hello UK!'],
]);
echo "Credits needed: {$preview['creditsNeeded']} (balance {$preview['creditBalance']})";
echo $preview['hasSufficientCredits'] ? 'enough credits' : 'top up first';
echo "Sendable: {$preview['sendable']}, blocked: {$preview['blocked']}, duplicates: {$preview['duplicates']}";
foreach ($preview['blockedMessages'] as $blocked) {
    echo "{$blocked['to']}: {$blocked['reason']}\n";
}
// 'byCountry' breaks the run down per country with its credits and tier.
```

### Iterate All Messages

`each()` requests page after page (up to 100 messages each, set with
`batchSize`) until it has yielded every matching message, so on a large
account it makes many requests; `break` out of the loop to stop early.

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
    'to' => ['+14155550123', '+14155550178'],
    'text' => 'Hey team - quick sync at noon?',
    // 'from' => '+15125550100',           // optional; omit to use your default sender
    // 'mediaUrls' => ['https://.../a.jpg'], // optional; text or mediaUrls required
    // 'messageType' => 'marketing',         // 'transactional' is the default for group MMS;
    //                                       // 'marketing' is subject to quiet hours
]);

echo $group['id'];                // "msg_xxx"
echo $group['status'];            // "sent" ("delivered" when simulated)
echo $group['group_message_id'];  // "grp_xxx" (present on live sends)

// Recipients: on a live send each entry is an array with the per-recipient
// status (['phoneNumber' => '+14155550123', 'status' => 'queued']); on a
// simulated send each entry is the phone number string.
foreach ($group['to'] as $r) {
    echo is_array($r) ? "{$r['phoneNumber']} {$r['status']}\n" : "{$r}\n";
}

// On a test key (or while verification is pending) the send is simulated and
// the response carries 'simulated' => true instead of a real group id.
```

Billed per recipient at the MMS rate. Without the `group_mms` feature the API
answers 403 `feature_disabled` (`SendlyException`). A group message the
carrier refuses throws `ValidationException` (422 `send_failed`), and its
credits are refunded.

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

POSTs carry an automatically generated `Idempotency-Key`. The client keeps
that key for every retry it makes on its own (after a 5xx, a timeout, a
connection failure, or the busy key-check 429), so a retry of a request that
already reached the API returns the original result instead of sending and
charging again. Uploads also carry a key, but the API does not deduplicate
them, so a retried upload can store the file twice.

On the endpoints that deduplicate (sends, batch, group, schedule, conversation
replies, draft approval, verify, number purchase, credit transfers, enterprise
deposits and provisioning, WhatsApp signup and template creation, calls, and
RCS and short-code writes) the API records the answer under the key for a 2xx
and for every 4xx except 429, and repeating the request with the same key
within 24 hours returns that recorded answer. A 5xx or a 429 is never recorded, so a retry under the same
key runs the request again. Reusing one of your own keys with a different body
is refused with 422 `idempotency_key_mismatch` (`ValidationException`). Other
POSTs ignore the key, so a retried call there runs again.

Pass your own key (1-255 printable ASCII characters) when the guarantee needs
to outlive the process, such as a job queue that re-runs after a crash.
`sendBatch()` sends no automatic key, because the API already deduplicates
identical batches by their contents.

```php
$message = $client->messages()->send(
    '+12125550123',
    'Your order has shipped!',
    idempotencyKey: 'order-4821-shipped'
);
```

## Numbers

Browse the countries Sendly provisions numbers in, search live availability, buy
a number, list the numbers on your account, inspect one, make a number your
default sender or keep a number scheduled for release, and release a number.

```php
// Countries Sendly can provision in, and the number types available in each
$countries = $client->numbers()->listCountries();
foreach ($countries['countries'] as $c) {
    echo "{$c['code']} {$c['name']}: " . implode(', ', $c['numberTypes']) . "\n";
}

// Search live availability. Monthly costs come back already customer-priced.
$available = $client->numbers()->listAvailable([
    'country' => 'GB',
    'type' => 'mobile',
    // 'contains' => '207',
]);
foreach ($available['numbers'] as $n) {
    echo "{$n['phoneNumber']} {$n['monthlyCost']} {$n['currency']}\n";
}

// Buy one. Provisioning is asynchronous — check `status`.
$order = $client->numbers()->buy([
    'phoneNumber' => $available['numbers'][0]['phoneNumber'],
    'countryCode' => 'GB',
    'phoneNumberType' => 'mobile',
    'monthlyCost' => $available['numbers'][0]['monthlyCost'],
]);

if ($order['status'] === 'provisioning') {
    // Poll list() until the number reports as active.
} else {
    // 'documents_required' or 'payment_required': hand the user action.url to
    // finish (show them action.code), then re-call buy() with the SAME body
    // plus 'actionCode' => $order['action']['actionCode'] — the 32-hex id, NOT
    // the short display-only action.code.
    echo $order['action']['url'];
    echo $order['action']['code'];
}

// List your numbers
$result = $client->numbers()->list();
foreach ($result['numbers'] as $n) {
    echo "{$n['phoneNumber']} — {$n['status']} ({$n['phoneNumberType']})\n";
}

// Get a single number (includes `isDefault`)
$number = $client->numbers()->get('num_abc123');
echo $number['phoneNumber']; // "+12125550123"
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
$link = $client->links()->create('https://acme.example/spring-sale?utm_source=sms');
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

Connect a number you own to WhatsApp, set up its profile, photo, ice
breakers, commands and calling, create Meta-reviewed message templates, check
24-hour conversation windows, and send WhatsApp messages by passing
`'channel' => 'whatsapp'` to `messages()->send()`.

Connecting a number is a one-time $19 setup (no monthly fee). The first number
ends with a human step: the signup returns a `connectUrl` that a person must
open in a browser and log in with Facebook to link their WhatsApp Business
Account. More numbers can join that account without it: WhatsApp texts or
calls the number a 6-digit code, which you submit.
Free-form text and media only deliver inside an open 24-hour window (the
recipient messaged you in the last 24h); an approved template works anytime.

> **Note:** The WhatsApp channel is being rolled out gradually and is not yet
> generally available. It is enabled per person: the user who owns the API
> key, not the workspace. While it is off, the `/api/v1/whatsapp/*`
> management routes return 404 `not_found` (thrown as `NotFoundException`),
> and a `'channel' => 'whatsapp'` send throws `SendlyException` (HTTP 403,
> `whatsapp_not_enabled`).

Sends go through `messages()->send()` with `'channel' => 'whatsapp'` and need
`sms:send`, not `whatsapp:write`. Reads (`signup->get`, templates, the window,
senders and sender profiles) need `whatsapp:read` and accept test keys.
Signup, template create/edit/delete and profile edits need `whatsapp:write`
and a live key (otherwise 403 `whatsapp_requires_live_key`). Sends need a
live key too. In a team workspace, connecting and profile edits need an owner
or admin (`settings:write`), and template writes need an owner, admin or
member (`templates:write`). A missing role returns 403
`insufficient_permissions`.

While WhatsApp connections are unavailable a Facebook signup answers 503
`whatsapp_unavailable` before charging anything, with `retryAfter: 3600` in
the body and a `Retry-After: 3600` header; the client retries it like any
5xx, then throws `SendlyException`. Only signup returns it; no send does. If
the connection fails, the $19 fee is refunded automatically; once a number
has connected, a later disconnect gets nothing back. After 5 failed, charged signups in 24 hours it answers 429
`whatsapp_signup_limit_reached`, thrown as `RateLimitException`: try again the
next day. A send WhatsApp refuses throws `ValidationException` (422
`whatsapp_send_failed`, final; cached under the idempotency key and replayed
for 24 hours). A 502 `whatsapp_send_failed` means the message provably never
reached the carrier, so it was not sent and is safe to send again; it is never
cached, and is retried like any 5xx under the same idempotency key. Neither is
charged. A 409 `whatsapp_send_unconfirmed` (`SendlyException`) means the
outcome is unknown: the message was marked failed and refunded but may still
be delivered, so check before sending it again (it could arrive twice). It is
not retried automatically.

Adding a number with `businessAccountId` runs the same number checks, fee,
refund and daily limit as a Facebook connection. It answers 404
`whatsapp_business_account_not_found` when no connected account with that id
is in the workspace, 400 `display_name_required` when neither you nor the
account supplies a display name, and `whatsapp_verification_start_failed`
when WhatsApp won't send the code (422 refused, 502 unreachable; the signup
fails and the fee is refunded). The client never retries these three calls
after a 5xx or a failed connection; it throws at once instead: `create()` with
`businessAccountId` (a retry could start another signup with its own charge
and refund that counts toward the daily limit), `verify()` (every submission
uses up one of the five attempts) and `uploadProfilePhoto()`. `resend()` is
retried like any other call. `verify()` and `resend()` need the same scope,
key and role as signup. The profile photo, ice breakers and commands, and calling
follow the profile rules: `getConversationalComponents()` needs
`whatsapp:read`, and the changes need `whatsapp:write`, a live key and, in a
team workspace, an owner or admin. WhatsApp only allows calling once the
account may message at least 2,000 people a day and its display name is
approved (otherwise 422 `whatsapp_calling_unavailable`). Calls from WhatsApp
users are billed at the normal inbound rate, and the API has no endpoint for
placing WhatsApp calls.

Template pre-flight refusals are 400s: `template_category_invalid` (category
missing or not one of `UTILITY`, `AUTHENTICATION` and `MARKETING`; there is
no default), `template_authentication_otp_button_required`,
`template_authentication_no_links` (a link in the body or a URL button on an
authentication template) and `template_header_variable_unsupported`. On
create, 404 `whatsapp_sender_not_connected` is checked first. An update can't
change the category, and a marketing template without an opt-out button only
gets a warning.

Pricing: free-form text or media inside the 24-hour window costs 1 credit
each for the first 1,000 per sending number per calendar month (UTC), then
the destination's utility template price; countries without a listed price
use the default utility price of 12 credits. Templates are priced by category
and destination country; countries without a listed price use 33
(marketing), 12 (utility) and 12 (authentication) credits. A failed send
gives its slot back.

```php
// 1. Connect a number ($19 one-time). A human must finish the connect URL.
$signup = $client->whatsapp()->signup->create('+12125550147');
echo $signup['connectUrl']; // hand this to a person to complete in a browser

// Poll until active. After the Facebook step the signup stays "registering"
// while WhatsApp activates the number. Activation usually takes a few minutes
// but can take hours. If it hasn't finished about 6 hours after the session
// began, the session fails with registration_timeout and the fee is refunded.
$status = $client->whatsapp()->signup->get($signup['id']);
echo $status['status']; // "initiated" -> "registering" -> "active" (or "failed")
if ($status['status'] === 'failed') {
    print_r($status['failureReasons']); // e.g. ['waba_mismatch']; the fee is refunded
}

// List connected senders
$result = $client->whatsapp()->senders->list();
foreach ($result['senders'] as $s) {
    echo "{$s['phoneNumber']} ({$s['displayName']}) — {$s['status']}\n";
    // $s['businessAccountId'], $s['businessName'], $s['callingEnabled'],
    // $s['outboundCallingAllowed']
}

// Add another number to a connected account ($19 one-time, no Facebook step).
// Take the account id from an active sender: the list is newest first, and a
// pending sender's businessAccountId is null. A null id starts a Facebook
// connection instead, and a blank one is refused.
// WhatsApp sends the number a 6-digit code by text ('sms') or call ('voice').
$active = array_values(array_filter(
    $result['senders'],
    fn($s) => $s['status'] === 'active' && $s['businessAccountId'] !== null
));
$added = $client->whatsapp()->signup->create('+12125550148', [
    'businessAccountId' => $active[0]['businessAccountId'],
    'verificationMethod' => 'sms',
]);
echo $added['status']; // "verifying"

// While verifying, get() returns the code once WhatsApp's text reaches the
// number (else null), so you can submit it without a person reading it out
$code = null;
for ($i = 0; $i < 10 && $code === null; $i++) {
    sleep(3);
    $code = $client->whatsapp()->signup->get($added['id'])['verificationCode'] ?? null;
}
if ($code !== null) {
    try {
        $client->whatsapp()->signup->verify($added['id'], $code); // "active"
    } catch (\Sendly\Exceptions\ValidationException $e) {
        // 422 whatsapp_verification_code_invalid. The fifth wrong code throws
        // SendlyException (409 whatsapp_verification_failed) instead: the
        // signup fails and the fee is refunded.
        echo $e->getResponseBody()['attemptsRemaining'];
    }
} else {
    // No code after 30 seconds? Ask for another, by voice call this time.
    // A resend sooner than 30 seconds after the signup last changed throws
    // RateLimitException (429 whatsapp_verification_resend_too_soon).
    $client->whatsapp()->signup->resend($added['id'], 'voice');
}

// Read and update a sender's business profile (what recipients see when
// they open your details in WhatsApp)
$profile = $client->whatsapp()->senders->getProfile('+12125550147');
echo $profile['displayName'];
echo $profile['about'];

$client->whatsapp()->senders->updateProfile('+12125550147', [
    'about' => 'Fresh roasted coffee, delivered.',   // max 139 chars
    'description' => 'Small-batch roaster shipping nationwide.', // max 512
    'website' => 'https://acme.example',
]);

// Profile photo: a square JPEG or PNG up to 5 MB, at least 192 px wide
$profile = $client->whatsapp()->senders->uploadProfilePhoto('+12125550147', 'logo.png');
echo $profile['profilePhotoUrl'];
$client->whatsapp()->senders->deleteProfilePhoto('+12125550147');

// Ice breakers (up to 4, shown when someone first opens the chat) and
// commands (up to 30, shown when they type "/"). Each list you pass replaces
// the stored one; [] clears it; a list you leave out is kept.
$client->whatsapp()->senders->updateConversationalComponents('+12125550147', [
    'iceBreakers' => ['Where is my order?', 'Opening hours'],
    'commands' => [['command' => 'track', 'description' => 'Track an order']],
]);
$components = $client->whatsapp()->senders->getConversationalComponents('+12125550147');

// WhatsApp calling: WhatsApp users can call the number, and it rings like a
// phone call. Needs voice on for the number first (409 voice_not_enabled).
$calling = $client->whatsapp()->senders->setCalling('+12125550147', true);
echo $calling['callingEnabled'] ? 'on' : 'off';

// 2. Create a template (Meta reviews it, usually 24-48h). Variables go in the
// body and buttons only: a header containing {{1}} is refused with
// template_header_variable_unsupported.
$template = $client->whatsapp()->templates->create([
    'sender' => '+12125550147',
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

// 3. Check the 24-hour window, then send. The response is exactly
// ['open' => ..., 'expiresAt' => ...]: no window on record gives open false
// and expiresAt null; an expired one gives open false and the past expiresAt.
$window = $client->whatsapp()->window('+12125550147', '+12125550123');

if ($window['open']) {
    // Free-form text inside the window
    $message = $client->messages()->send([
        'channel' => 'whatsapp',
        'to' => '+12125550123',
        'from' => '+12125550147',
        'text' => 'Your table is ready!',
    ]);
} else {
    // An approved template works regardless of the window
    $message = $client->messages()->send([
        'channel' => 'whatsapp',
        'to' => '+12125550123',
        'from' => '+12125550147',
        'template' => [
            'name' => 'order_shipped',
            'language' => 'en_US',
            'variables' => ['1' => 'Acme Inc', '2' => '#4821'],
        ],
    ]);
}

echo $message['id'];
echo $message['whatsapp']['kind']; // "text" or "template"
// Priced as described above: in-window replies use the per-number monthly
// allowance of 1,000 at 1 credit each, templates by category and country.
echo $message['creditsUsed'];

// Media with a caption (inside the window; exactly one media URL per message)
$client->messages()->send([
    'channel' => 'whatsapp',
    'to' => '+12125550123',
    'from' => '+12125550147',
    'mediaUrls' => ['https://acme.example/receipt.pdf'],
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
    'contact' => ['firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'jane@acme.example', 'phoneNumber' => '+12125550123'],
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
        'phoneNumber' => ['number' => '+12125550123', 'label' => 'Call us'],
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
    '+12125550123',
    ['phoneNumber' => '+12125550147', 'label' => 'QA phone'],
]);

$client->rcs()->agents->update($agent['id'], [
    'campaign' => [
        'companyOverview' => 'Acme Coffee roasts small-batch coffee and runs cafes with pickup and delivery.',
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
            'callToAction' => 'Tick the box at checkout to get order updates from Acme Coffee. Reply STOP to opt out, HELP for help.',
            'callToActionUrl' => 'https://acme.example/checkout',
            'callToActionMediaUrl' => 'https://acme.example/rcs/checkout-opt-in.png',
            'doubleOptIn' => false,
            'optInMessage' => 'Acme Coffee: you are opted in to order updates. Reply STOP to opt out.',
            'helpResponse' => 'Acme Coffee: reply STOP to opt out or call +1 212 555 0123.',
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
$cap = $client->rcs()->capability('+12125550123');
echo $cap['capable'] ? 'RCS' : 'would fall back to SMS';

// Text with suggested replies and actions
$message = $client->messages()->send([
    'channel' => 'rcs',
    'to' => '+12125550123',
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
    'to' => '+12125550123',
    'card' => [
        'title' => 'Spring collection',
        'description' => 'New arrivals are in - take a look.',
        'mediaUrl' => 'https://acme.example/spring.jpg', // public JPEG/PNG/GIF
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
    'to' => '+12125550123',
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
        'to' => '+15125550123',
        'agentId' => '3c4d5e6f-7081-4293-a4b5-c6d7e8f90a1b',
        'from' => '+15125550100', // optional when only one number is voice-enabled
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
    // 400 from_number_not_supported: calls go out only from US or Canadian numbers
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

`CallStatus`, `CallDirection`, `CallKind`, `CallChannel`, `CallHandledBy`,
`CallBilling`, `CallRecordingStatus` and `CallErrorCode` in `Sendly\Resources`
hold the string values the endpoints use. A call's `channel` says how the other
party reached it: `phone`, `whatsapp` or `browser` (more may be added). Calls
placed through the API are phone calls. A call's `billing` is `metered` while a phone call is
charged per minute, `settled` once it has ended, and `unbilled` for calls that
were never charged (browser-to-browser calls between teammates are free).
`hangupClass` says why a call ended: `normal`, `caller_hung_up`,
`callee_hung_up` and `agent_agent_hangup` are ordinary endings; `ring_timeout`,
`callee_busy`, `callee_declined` and `caller_cancelled` mean it never
connected; `max_duration` (60 minutes) and `credits_exhausted` mean the
platform cut it short.

The `call.started`, `call.completed` and `call.recording.ready` webhooks carry
the call at `$event->object` in snake_case (`channel`, `handled_by`,
`duration_secs`, `credits_charged`, `hangup_class`, `recording_status`,
`billing`, `metadata`).

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
    $number = $client->voice->numbers->registerEmergencyAddress('+15125550100', [
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
$number = $client->voice->numbers->update('+15125550100', [
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
        print_r($e->getResponseBody()['numbers']); // ['+15125550100']
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
// Create a webhook endpoint. create() returns a WebhookCreatedResponse: the
// webhook itself is nested under ->webhook, and ->secret is shown only here.
$created = $client->webhooks()->create(
    'https://acme.example/webhooks/sendly',
    ['message.delivered', 'message.failed']
);

echo $created->webhook->id;  // "whk_..."
echo $created->secret;       // "whsec_..." — store securely, shown once!

// List all webhooks (array of Webhook objects)
$webhooks = $client->webhooks()->list();
foreach ($webhooks as $wh) {
    echo "{$wh->url} {$wh->successRate}% " . ($wh->isHealthy() ? 'healthy' : 'degraded') . "\n";
}

// Get a specific webhook. Delivery stats (successRate, totalDeliveries) are
// filled in by list() only; get() returns them as 0.
$wh = $client->webhooks()->get('whk_xxx');
echo $wh->circuitState; // closed, open, half_open

// Update a webhook
$client->webhooks()->update('whk_xxx', [
    'url' => 'https://new-endpoint.acme.example/webhook',
    'events' => ['message.delivered', 'message.failed', 'message.sent'],
    // 'isActive' => false, 'mode' => 'live', 'description' => '...'
]);

// Test a webhook. A delivered test returns a WebhookTestResult; a test your
// endpoint fails throws ValidationException with the API's message, and
// getResponseBody()['success'] is false. An unknown webhook id also throws
// ValidationException here, not the NotFoundException get() throws.
try {
    $result = $client->webhooks()->test('whk_xxx');
    echo "{$result->statusCode} in {$result->responseTimeMs}ms ({$result->deliveryId})";
} catch (\Sendly\Exceptions\ValidationException $e) {
    echo $e->getMessage();
}

// Rotate webhook secret. Deliveries are signed with the new secret from the
// moment it is issued, so switch your endpoint over straight away.
$rotation = $client->webhooks()->rotateSecret('whk_xxx');
echo $rotation->secret; // shown only once

// Inspect and retry deliveries
$deliveries = $client->webhooks()->listDeliveries('whk_xxx', ['limit' => 20]);
foreach ($deliveries as $d) {
    echo "{$d->eventType} -> {$d->httpStatus}\n";
}
$one = $client->webhooks()->getDelivery('whk_xxx', 'del_xxx');
$client->webhooks()->retryDelivery('whk_xxx', 'del_xxx');

// Recover after an outage. Both are refused with HTTP 409 while the circuit is
// open, so reset it first.
$client->webhooks()->resetCircuit('whk_xxx');
// The window runs from 'since' to 'until' (default: now) and is at most 7 days.
$since = gmdate('Y-m-d\TH:i:s\Z', strtotime('-2 days'));
// Re-fire deliveries we recorded but could not deliver:
$client->webhooks()->redeliver('whk_xxx', ['since' => $since]);
// Synthesize events that never got an audit row. A synthesized event reuses
// the id the original dispatch used, so dedupe on $event->id, not on
// data.object.id: a message's sent and delivered events share it.
$client->webhooks()->backfill('whk_xxx', ['since' => $since]);

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

## Verify (OTP)

Send and check one-time codes, or hand the whole flow to a Sendly-hosted page.
Request and response fields on this resource are snake_case.

```php
// Send a code
$verification = $client->verify()->send('+12125550123', [
    'app_name' => 'Acme',
    'code_length' => 6,
    'timeout_secs' => 300,
    // 'template_id' => 'tpl_xxx', 'profile_id' => 'vp_xxx',
]);
echo $verification['id'];
echo $verification['status'];
echo $verification['expires_at'];

// On a test key the response carries the code so you can assert on it
if ($verification['sandbox']) {
    echo $verification['sandbox_code'];
}

// Check it. A wrong code throws ValidationException (400 invalid_code) and
// the error body carries remaining_attempts.
try {
    $result = $client->verify()->check($verification['id'], '123456');
    echo $result['status'];                    // "verified"
} catch (\Sendly\Exceptions\ValidationException $e) {
    echo $e->getResponseBody()['remaining_attempts'] ?? '';
}

// Resend, fetch, list
$client->verify()->resend($verification['id']);
$one = $client->verify()->get($verification['id']);
$all = $client->verify()->list(['limit' => 20]);
$verified = $client->verify()->list(['status' => 'verified', 'limit' => 20]);
foreach ($verified['verifications'] as $v) {
    echo "{$v['phone']}: {$v['status']}\n";
}
```

### Hosted verification sessions

Sendly hosts the phone-entry and code-entry pages; you get a one-time token back.

```php
$session = $client->verify()->sessions->create([
    'success_url' => 'https://acme.example/verified',
    'cancel_url' => 'https://acme.example/cancel',
    'brand_name' => 'Acme',
    'brand_color' => '#6B4F3A',
    'metadata' => ['userId' => 'u_123'],
]);
// Send the user to the session's URL, then validate the token they come back with.
// A returned result is always valid; a bad, used or unverified token throws.
try {
    $check = $client->verify()->sessions->validate($tokenFromCallback);
    echo $check['phone'];
} catch (\Sendly\Exceptions\ValidationException $e) {
    echo $e->getApiErrorCode(); // invalid_token, token_already_used or not_verified
}
```

## Templates

Reusable message bodies with `{{variable}}` placeholders, plus Sendly's presets.

```php
$all = $client->templates()->list();
$presets = $client->templates()->presets();

$template = $client->templates()->create('Order shipped', 'Hi {{name}}, order {{id}} shipped!');
$client->templates()->update($template['id'], ['text' => 'Hi {{name}}, {{id}} is on its way!']);
$client->templates()->publish($template['id']);

$preview = $client->templates()->preview($template['id'], ['name' => 'Sam', 'id' => '#4821']);
$copy = $client->templates()->clone($template['id'], 'Order shipped v2');
$client->templates()->delete($template['id']);

// Draft one with AI
$generated = $client->templates()->generate('A sign-in code message for the Acme app', 'transactional');
echo $generated['text'];
```

## Contacts & Lists

```php
// Contacts
$contacts = $client->contacts()->list(['limit' => 50, 'search' => 'sam']);
$contact = $client->contacts()->create('+12125550123', [
    'name' => 'Sam Rivera',
    'email' => 'sam@acme.example',
    'metadata' => ['plan' => 'pro'],
]);
$client->contacts()->update($contact['id'], ['name' => 'Sam R.']);

// Auto-pagination
foreach ($client->contacts()->each(['listId' => 'lst_xxx']) as $c) {
    echo $c['phone_number'] . "\n";
}

// Bulk import
$result = $client->contacts()->import([
    ['phone' => '+12125550123', 'name' => 'Sam'],
    ['phone' => '+12125550147', 'name' => 'Alex'],
], ['listId' => 'lst_xxx']);
echo "{$result['imported']} imported, {$result['skippedDuplicates']} duplicates";

// Contacts are auto-flagged invalid after a terminal bad-number failure or a
// carrier lookup. Clear the flag when the call was wrong:
$client->contacts()->markValid($contact['id']);
$client->contacts()->bulkMarkValid(['listId' => 'lst_xxx']); // or ['ids' => [...]]

// Ask for a background carrier lookup (runs 1-5 minutes)
$client->contacts()->checkNumbers(['listId' => 'lst_xxx']);

// Lists - reached through the lists() method
$lists = $client->contacts()->lists();
$list = $lists->create('VIP customers', 'Top accounts');
$lists->addContacts($list['id'], [$contact['id']]);
$lists->removeContact($list['id'], $contact['id']);
$lists->update($list['id'], ['name' => 'VIPs']);

// The API returns every list in one response and ignores limit and offset,
// so each() makes a single request
foreach ($lists->each() as $l) {
    echo $l['name'] . "\n";
}

// Deletes return ['success' => true]; an error response throws
$lists->delete($list['id']);
$client->contacts()->delete($contact['id']);
```

## Campaigns

Send one message to a contact list, now or on a schedule. Every campaign is
sent as a marketing message, subject to quiet hours, and targets one contact
list. The `segmentId` and `messageType` options are deprecated: the API reads
neither.

```php
use Sendly\Resources\Campaigns;

$campaign = $client->campaigns()->create('Spring sale', 'Our spring sale is live!', [
    'contactListId' => 'lst_xxx',
]);

// Cost and audience before you commit
$preview = $client->campaigns()->preview($campaign['id']);
// The v1 preview answers with recipientCount, totalRecipients, estimatedCredits,
// currentBalance, hasEnoughCredits, blockedCount, sendableCount, byCountry and
// warnings. There is no estimatedSegments and no id.
echo "{$preview['recipientCount']} recipients, {$preview['estimatedCredits']} credits";

// Send now: the result is the batch the campaign went out as
$result = $client->campaigns()->send($campaign['id']);
echo "{$result['batchId']}: {$result['sent']}/{$result['total']} sent";
// or
$client->campaigns()->schedule($campaign['id'], gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 day')));
$client->campaigns()->cancel($campaign['id']);

$client->campaigns()->clone($campaign['id'], 'Spring sale round 2');

// list() returns 'campaigns' with 'total', 'limit' and 'offset'
foreach ($client->campaigns()->each(['status' => Campaigns::STATUS_COMPLETED]) as $c) {
    echo $c['name'] . "\n";
}

$client->campaigns()->delete($campaign['id']); // ['success' => true]
```

Campaign status constants live on the class: `Campaigns::STATUS_DRAFT`,
`STATUS_SCHEDULED`, `STATUS_SENDING`, `STATUS_COMPLETED` (what a sent campaign
becomes), `STATUS_FAILED` and `STATUS_CANCELLED`. `STATUS_SENT` is never
returned, though as a `list()` filter it matches completed campaigns, and
`STATUS_PAUSED` is deprecated: campaigns cannot be paused.

> **Deprecated:** `pause()`, `resume()`, `stats()` and `recipients()` have no
> route on the API and fail with a 404. Read the campaign with `get()` instead.

## Conversations

Two-way threads, with the AI helpers built on them.

```php
$threads = $client->conversations()->list(['status' => 'active', 'limit' => 20]);
foreach ($threads['data'] as $t) {
    echo $t['id'] . "\n";
}

$thread = $client->conversations()->get('conv_xxx', [
    'includeMessages' => true,
    'messageLimit' => 50,
]);

$client->conversations()->reply('conv_xxx', 'Thanks - we are on it!', [
    'messageType' => 'transactional',
]);

$client->conversations()->markRead('conv_xxx');
$client->conversations()->close('conv_xxx');
$client->conversations()->reopen('conv_xxx');
$client->conversations()->update('conv_xxx', ['tags' => ['vip']]);

// Labels on a thread
$client->conversations()->addLabels('conv_xxx', ['lbl_a', 'lbl_b']);
$client->conversations()->removeLabel('conv_xxx', 'lbl_a');

// Feed a thread to your own model, or use Sendly's suggestions
$context = $client->conversations()->getContext('conv_xxx', 20);
echo $context['context'];
$suggestions = $client->conversations()->suggestReplies('conv_xxx');
```

## Labels, Drafts and Rules

```php
// Labels
$label = $client->labels()->create('Billing', ['color' => '#6B4F3A']);
$all = $client->labels()->list();

// Drafts - a reply staged for approval before it sends
$draft = $client->drafts()->create('conv_xxx', 'Proposed reply text');
$client->drafts()->update($draft['id'], ['text' => 'Revised reply text']);
$client->drafts()->approve($draft['id']);       // sends it
// or turn a pending draft down instead of approving it:
// $client->drafts()->reject($draft['id'], 'Wrong tone');
$pending = $client->drafts()->list(['status' => 'pending']);

// Rules - label threads by the AI classification of inbound messages.
// Conditions and actions are each one associative array; a list of arrays
// is merged into one before it is sent.
$rule = $client->rules()->create(
    'Tag complaints',
    ['intent' => 'complaint', 'sentiment' => 'negative', 'intentConfidenceMin' => 0.8],
    ['addLabels' => [$label['id']]], // or 'closeConversation' => true
    ['priority' => 10]
);
$client->rules()->update($rule['id'], ['priority' => 1]);
$client->rules()->delete($rule['id']);
$client->labels()->delete($label['id']);
```

## Media

Upload an attachment and send it as MMS.

```php
$file = $client->media()->upload('/path/to/photo.jpg');
echo $file->id;
echo $file->url;
echo $file->contentType;
echo $file->sizeBytes;

$client->messages()->send([
    'to' => '+12125550123',
    'text' => 'Here it is!',
    'mediaUrls' => [$file->url],
]);
```

## 10DLC

Register your business so you can text from US local (10-digit) numbers. Brand,
campaign and assignment writes need a live key with the `tendlc:write` scope.

```php
// 1. Brand - starts 'pending', poll until 'verified' (or 'failed')
$brand = $client->tenDlc()->createBrand([
    'legalName' => 'Acme Coffee LLC',
    'ein' => '12-3456789',
    'entityType' => 'PRIVATE_PROFIT',
    'website' => 'https://acme.example',
    'email' => 'ops@acme.example',
])['data'];

$state = $client->tenDlc()->getBrand($brand['id'])['data'];
echo $state['status'];
print_r($state['failureReasons']);

// Pre-check a use case before spending a campaign on it
$check = $client->tenDlc()->qualify($brand['id'], 'MIXED')['data'];
echo $check['qualified'] ? 'ok' : $check['reason'];

// 2. Campaign - starts 'pending', poll until 'active'
$campaign = $client->tenDlc()->createCampaign([
    'brandId' => $brand['id'],
    'useCase' => 'MIXED',
    'description' => 'Order updates and occasional offers.',
    'messageFlow' => 'Customers opt in at checkout with a checkbox.',
    'sampleMessages' => ['Your order #4821 is ready.', 'Reply STOP to opt out.'],
])['data'];

// 3. Assign a number you own to the active campaign
$assignment = $client->tenDlc()->assignNumber($campaign['id'], '+12125550123')['data'];
echo $assignment['status']; // "Active" once the number can send

$client->tenDlc()->listBrands();
$client->tenDlc()->listCampaigns();
$client->tenDlc()->listAssignments();
```

## Short Codes

This SDK still has **no short-code helper methods**. Short codes are reachable
over REST with the client's own request methods, which return the decoded
response and throw the same exceptions as every other call:

```php
// Your workspace's short codes
$codes = $client->get('/short_codes');
foreach ($codes['shortCodes'] as $c) {
    echo "{$c['shortCode']} {$c['status']} / {$c['reviewStatus']}\n";
}

// Start an application (one open application per workspace)
$request = $client->post('/short_codes/requests', [
    'useCase' => 'Order notifications and delivery alerts',
    'optInFlow' => 'Customers tick a consent box at checkout.',
    'sampleMessages' => ['Your order #4821 has shipped.'],
    'expectedMonthlyVolume' => '50000',
    'helpResponse' => 'Acme: reply STOP to opt out.',
    'stopConfirmation' => 'Acme: you are opted out and will get no more messages.',
]);
echo $request['id'];

// The application itself
$application = $client->get('/short_codes/application');
$client->put('/short_codes/application', ['useCase' => 'Updated use case']);
$client->post('/short_codes/application/preflight');
$client->post('/short_codes/application/submit');
```

Reads need an API key with the `short_codes:read` scope and writes
`short_codes:write`. Until short codes are enabled for your account these
endpoints read as absent and throw `NotFoundException`
(`short_codes_not_enabled`); a second application while one is open answers
409 `short_code_application_exists`.

A code's `status` moves `requested` → `provisioning` → `parked` → `active`
(`cancelled` ends it), and our review gate's `reviewStatus` moves `draft` →
`awaiting_review` → `approved_for_filing` → `filed` (`rejected` ends it). A code
stays `parked` until brand and content-provider registration finish. Subscribe
to the `short_code.action_required`, `short_code.rejected`, `short_code.filed`
and `short_code.live` webhooks to follow it without polling.

## Business Upgrade

When a customer forms a new legal entity, reserve a new toll-free number under
it and swap over on carrier approval — the current number keeps sending
throughout the 1-2 week review.

```php
// Check the payload before you file it - advisory only, no writes
$check = $client->businessUpgrade()->preflight(['businessName' => 'Acme Coffee LLC']);
echo $check['verdict'];
print_r($check['issues']);

// Best-known values across the caller's verified workspaces
$prefill = $client->businessUpgrade()->bestPrefill();

// File it, with the IRS letter (CP-575 / 147C) as multipart
$upgrade = $client->businessUpgrade()->start('ws_xxx', [
    'businessName' => 'Acme Coffee LLC',
    'brn' => '12-3456789',
    'brnType' => 'EIN',
    'brnCountry' => 'US',
    'entityType' => 'PRIVATE_PROFIT',
    'website' => 'https://acme.example',
], ['einDocPath' => '/path/to/cp575.pdf']);
echo $upgrade['status']; // "provisioning"

// The pending upgrade carries the new toll-free number once one is reserved
$status = $client->businessUpgrade()->status('ws_xxx');
echo $status['pending']['tollFreeNumber'] ?? '';
$client->businessUpgrade()->resubmit('ws_xxx', ['website' => 'https://acme.example/about']);
$client->businessUpgrade()->cancel('ws_xxx');

// After approval, decide what happens to the old number
$client->businessUpgrade()->setDisposition('ws_xxx', [
    'disposition' => 'moved',            // or 'released'
    'targetWorkspaceId' => 'ws_yyy',     // required when 'moved'
]);
```

## Account & Credits

```php
// Get account information
$account = $client->account()->get();
echo $account->email;
echo $account->organization['name'] ?? '';   // the workspace the key belongs to
echo $account->apiKey['type'];                // the calling key: "test" or "live"

// Limits and business verification live on the account object
echo $account->limits->messagesPerMinute;
echo $account->verification->status ?? 'none'; // "verified", "pending", "rejected", ...
echo $account->verification->isVerified() ? 'verified' : 'not verified';

// Check credit balance
$credits = $client->account()->credits();
echo "Available: {$credits->availableBalance} credits"; // what you can spend now
echo "Total: {$credits->balance} credits";
echo "Reserved: {$credits->reservedBalance} credits";   // held for messages still sending
echo $credits->billingMode; // "prepaid", or "pooled" for a workspace on an enterprise credit pool

// View credit transaction history (CreditTransaction::TYPE_PURCHASE, TYPE_USAGE,
// TYPE_REFUND, TYPE_BONUS, TYPE_TRANSFER, TYPE_ADMIN_GRANT, TYPE_ADMIN_SEED)
$transactions = $client->account()->transactions(['limit' => 50]);
foreach ($transactions as $tx) {
    echo "{$tx->type}: {$tx->amount} credits - {$tx->description}\n";
}

// Move credits to another workspace you own
$client->account()->transferCredits('org_xxx', 500);

// List API keys
$keys = $client->account()->apiKeys();
foreach ($keys as $key) {
    echo "{$key->name} ({$key->type}): {$key->prefix} " . ($key->isActive ? 'active' : 'revoked') . "\n";
    echo implode(', ', $key->scopes) . "\n";
}

// Get a specific API key ($key->revokedAt is set once it is revoked)
$key = $client->account()->getApiKey('key_xxx');

// Get API key usage stats (returned as a plain array)
$usage = $client->account()->getApiKeyUsage('key_xxx');
print_r($usage);

// Create a new API key. Without 'type' the API creates a test key.
$created = $client->account()->createApiKey('Production Key', [
    'type' => 'live',                      // 'test' or 'live'; anything else throws before sending
    'scopes' => ['sms:send', 'sms:read'],  // optional; only scopes the calling key has
    'expiresAt' => '2027-01-01T00:00:00Z', // optional
]);
echo "New key: {$created['key']}"; // Only shown once!
echo $created['apiKey']->isLive() ? 'live' : 'test';

// Rotate an API key (old key stays valid for a grace period, default 24h)
$rotation = $client->account()->rotateApiKey('key_xxx', [
    'gracePeriodHours' => 48, // optional; 24-168 inclusive
]);
echo "New key: {$rotation['newKey']['key']}"; // Only shown once!
echo $rotation['message'];                     // "Old key will expire in 48 hours"

// Revoke an API key
$client->account()->revokeApiKey('key_xxx');
```

A live key needs a verified business and a credit balance: without them
`createApiKey()` gets a 403 `verification_required` (`SendlyException`) or a
402 `credits_required` (`InsufficientCreditsException`). Asking for a scope the
calling key lacks is refused with 403 `insufficient_permissions`; without
`scopes` the new key gets the calling key's scopes.

## Rate Limits

Requests are counted per API key in a fixed 60-second window that starts with the first request and resets when it runs out:

| Key | Requests per minute |
|-----|---------------------|
| Test (`sk_test_v1_*`) | 60 |
| Live (`sk_live_v1_*`) | 600 |
| Enterprise master | 3000 |

Over the limit the API answers `429 rate_limit_exceeded` and the SDK throws
`RateLimitException`; `getRetryAfter()` gives the seconds to wait, from the
`Retry-After` header or, when there is none, the body's `retryAfter` (as on
`verify()->send()`'s per-phone and daily limits). Responses also carry
`X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset`.

The client waits out one 429 itself: `too_many_concurrent_verifications`
(too many first-time API key checks running at once from your address). The
request never ran, so it is sent again after its `Retry-After`, with the same
idempotency key, within `maxRetries`. Every other 429 throws at once. Branch on
`getApiErrorCode()`:

```php
use Sendly\Exceptions\RateLimitException;

try {
    $client->messages()->send('+12125550123', 'Hello!', idempotencyKey: 'order-4821-hello');
} catch (RateLimitException $e) {
    if ($e->getApiErrorCode() === 'too_many_failed_key_attempts') {
        // Repeated wrong API keys from this address locked it out. Do not
        // retry: fix the key, then wait getRetryAfter() seconds, since until
        // the lockout ends the right key can be refused too.
        throw $e;
    }
    sleep(max(1, $e->getRetryAfter()));
    // then retry with the same idempotencyKey so the retry cannot double-send
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
    $message = $client->messages()->send('+12125550123', 'Hello!');
} catch (AuthenticationException $e) {
    // Invalid API key (401)
} catch (RateLimitException $e) {
    // 429. too_many_failed_key_attempts is a lockout for wrong API keys:
    // fix the key rather than retrying. Otherwise wait and retry.
    echo $e->getApiErrorCode() . ', retry after ' . $e->getRetryAfter() . ' seconds';
} catch (InsufficientCreditsException $e) {
    // Add more credits (402)
} catch (ValidationException $e) {
    // Invalid request: getCode() is 400 or 422, the status the API sent
    print_r($e->getDetails());
} catch (NotFoundException $e) {
    // Resource not found (404) - also how a channel that is not enabled
    // for your account reads: the endpoints are absent
} catch (NetworkException $e) {
    // Connection failed or timed out, after maxRetries attempts
} catch (SendlyException $e) {
    // Other error
    echo $e->getMessage();
    echo $e->getCode();          // HTTP status
    echo $e->getErrorCode();     // the SDK's class code, e.g. "VALIDATION_ERROR"
    echo $e->getApiErrorCode();  // the API's error code, e.g. "rcs_field_locked"
    print_r($e->getResponseBody()); // the decoded error body, when there was one
}
```

Every exception above extends `SendlyException`, so a single
`catch (SendlyException $e)` catches them all.

Some `ValidationException`s are thrown before any request is sent: a missing
required argument, text over 1,600 characters (counted in characters, not
bytes), and an id of `.` or `..`. An id like that would otherwise be resolved
as a path segment and reach a different endpoint, so every method, and a path
passed to `get()`, `post()`, `put()`, `patch()`, `delete()` or
`postMultipart()`, refuses it.

`WebhookSignatureException` is the exception: it extends PHP's `Exception`
directly, so catching `SendlyException` will **not** catch it. Catch it by name
in your webhook handler.

Some refusals carry extra detail in `getResponseBody()` — a 409 `agent_in_use`
lists the numbers the agent still answers under `numbers`, a 422
`invalid_address` carries a corrected address (or null) under `suggested`, and
a 422 `whatsapp_verification_code_invalid` says how many tries are left under
`attemptsRemaining`.

## Message Object

```php
$message->id;           // Unique identifier
$message->to;           // Recipient phone number
$message->from;         // string|null
$message->text;         // Message content
$message->status;       // queued, sent, delivered, failed, bounced, retrying, read, received
$message->direction;    // outbound, inbound
$message->segments;     // int
$message->creditsUsed;  // Credits consumed
$message->isSandbox;    // bool
$message->createdAt;    // DateTimeImmutable
$message->deliveredAt;  // DateTimeImmutable|null
$message->updatedAt;    // deprecated: deliveredAt, or createdAt before delivery
$message->errorCode;    // string|null
$message->errorMessage; // string|null, a failed message's reason
$message->retryCount;   // int
$message->metadata;     // array|null
$message->aiMetadata;   // array|null (AI classification on inbound messages)
$message->messageFormat; // "sms", "mms", "whatsapp" or "rcs"
$message->mediaUrls;    // array|null: MMS media, WhatsApp media or an RCS card image
$message->batchId;      // string|null, set on messages sent in a batch
$message->simulated;    // bool, true when nothing reached a handset
$message->simulatedReason; // string|null, why a live key's send was simulated

// Helper methods
$message->isSimulated(); // bool
$message->isDelivered(); // bool
$message->isFailed();    // bool
$message->isBounced();   // bool - carrier rejected
$message->isPending();   // bool - queued or sent

// Convert to array
$message->toArray();
```

## Message Status

| Status | Description |
|--------|-------------|
| `queued` | Message is queued for delivery |
| `sent` | Message was sent to carrier |
| `delivered` | Message was delivered |
| `failed` | Message delivery failed |
| `bounced` | Carrier rejected the message |
| `retrying` | Delivery failed and is being retried |
| `read` | Recipient read the message (channels that report it) |
| `received` | Inbound message from a recipient |

## Pricing Tiers

1 credit is $0.01. A message costs credits per segment, by destination country:

| Tier | Example countries | Credits per SMS |
|------|-------------------|-----------------|
| Domestic | US, CA | 2 |
| Tier 1 | GB, AU, PL, SE, BR | 8 |
| Tier 2 | FR, JP, IT, IN, ES | 12 |
| Tier 3 | DE, NL, MX, BE | 16 |
| Tier 4 | UA, VN, PA, GE | 24 |
| Tier 5 | IL, MY, PH, ID | 48 |

`previewBatch()` returns the exact per-country credits for a run before you
send it, and its `byCountry` breakdown names each country's tier.

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

The Enterprise API lets you programmatically manage workspaces, verification, credits, and API keys for multi-tenant platforms. It requires an enterprise master key — an ordinary live key (`sk_live_v1_…`) that has been marked as your organization's master key in the dashboard; what distinguishes it is the flag on the key, not the prefix. A non-master key is refused with 403 `enterprise_required`, and a master key whose enterprise account is inactive with 403 `enterprise_inactive`. Master keys also get the higher rate limit of 3,000 requests a minute.

### Quick Provision

Create a fully configured workspace in a single call:

```php
use Sendly\Sendly;

$client = new Sendly('sk_live_v1_your_master_key');

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
$ws = $client->enterprise->workspaces->create(['name' => 'Acme Insurance']);
$list = $client->enterprise->workspaces->list();
$detail = $client->enterprise->workspaces->get('ws_xxx');
$client->enterprise->workspaces->delete('ws_xxx');

// Provision up to 100 workspaces in one call. Each takes name and optionally
// sourceWorkspaceId, creditAmount and creditSourceWorkspaceId; for
// verification, inheritWithNewNumber, keys, opt-in pages or webhooks, use
// provision() one workspace at a time.
$bulk = $client->enterprise->workspaces->provisionBulk([
    ['name' => 'Acme Insurance - Dallas', 'sourceWorkspaceId' => 'ws_verified'],
    ['name' => 'Acme Insurance - Houston', 'sourceWorkspaceId' => 'ws_verified'],
]);

// Reuse a verified workspace's verification. With purchaseNewNumber the
// workspace is ordered its own toll-free number instead of sharing the
// source's; the result then has newNumber true, and tollFreeNumber is null
// when no number could be ordered.
$inherited = $client->enterprise->workspaces->inheritVerification('ws_xxx', [
    'sourceWorkspaceId' => 'ws_verified',
    'purchaseNewNumber' => true,
]);
echo $inherited['tollFreeNumber'] ?? 'no number ordered yet';
```

### Credits & API Keys

```php
$client->enterprise->workspaces->transferCredits('ws_dest', [
    'sourceWorkspaceId' => 'ws_source',
    'amount' => 5000,
]);

// name defaults to "API key" and type to 'test'. A live key needs the
// workspace to be verified and to have credits (403 / 402 otherwise).
$key = $client->enterprise->workspaces->createKey('ws_xxx', [
    'name' => 'Production',
    'type' => 'live',
]);
echo $key['key']; // shown only once

$client->enterprise->workspaces->revokeKey('ws_xxx', 'key_abc');
```

### Webhooks & Analytics

```php
$client->enterprise->webhooks->set(['url' => 'https://hooks.acme.example/enterprise']);
$overview = $client->enterprise->analytics->overview();
$messages = $client->enterprise->analytics->messages(['period' => '30d']);
$delivery = $client->enterprise->analytics->delivery();
$credits = $client->enterprise->analytics->credits(['period' => '30d']);
```

Full enterprise docs: [sendly.live/docs/enterprise](https://sendly.live/docs/enterprise)

---

## License

MIT
