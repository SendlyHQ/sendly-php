# sendly/sendly-php

## 4.2.0

### Minor Changes

- **Voice configuration over the API.** `$client->voice` (and `$client->voice()`) configures everything a phone call depends on. `voice->numbers` gains `list()`, `get($number)`, `update($number, $params)` and `registerEmergencyAddress($number, $params)`: switch voice on for a number, choose how it answers (`voiceMode` `none`, `ring_dashboard` or `agent`, with `agentId`), and register the emergency address a US or Canadian number needs before it can place calls ($1.50 a month; registering again replaces the address without a second charge). `$number` is the number's id or its E.164 phone number. `voice->agents` gains `list()`, `create()`, `get()`, `update()` and `delete()` for the AI agents that answer and place calls (up to 20 per workspace, each holding its own scoped sending key), and `voice->voices->list()` lists the voices they can speak with. Every method returns an array; lists come back under `data`. Reads need an API key with the `calls:read` scope, writes `calls:write` and a live key; in a team workspace, number changes also need a role that can change settings and agent changes a role that can manage API keys. `VoiceMode` holds the mode values, and `CallErrorCode` gains `AGENT_IN_USE`, `AGENT_LIMIT`, `INVALID_VOICE_MODE`, `INVALID_ADDRESS`, `E911_NOT_APPLICABLE`, `VOICE_ATTACH_FAILED`, `CARRIER_REFUSED` and `INSUFFICIENT_PERMISSIONS`.
- **`SendlyException::getResponseBody()`** returns the decoded JSON body of an API error response, for refusals that carry more than a code and a message: a 409 `agent_in_use` lists the numbers the agent still answers under `numbers`, and a 422 `invalid_address` carries a corrected address (or null) under `suggested`. It is null for errors raised before a request is sent and when the error response is not JSON (an HTML 502 page, for example).

### Patch Changes

- **A PATCH with nothing to send carries `{}` instead of `[]`.** `Sendly::patch()` used to encode an empty body as the JSON array `[]`; it now sends the empty JSON object `{}`. This applies to every resource method that sends an empty PATCH body, such as `account->revokeApiKey()`.
- **The `calls->recording()` docstring had the channels the wrong way round.** Agent-handled calls are recorded in stereo with the agent on the left channel and the other party on the right.

## 4.1.0

### Minor Changes

- **Voice calls over the API.** `$client->calls` (and `$client->calls()`) gains `create()`, `list()`, `get()`, `hangup()` and `recording()` against `/api/v1/calls`: place a phone call that one of your workspace's AI agents handles (`to` and `agentId` required; `from` when more than one number is voice-enabled; optional `context` for the agent and a string `metadata` map that comes back on every read and every `call.*` webhook), list calls newest first with `status` / `direction` / `kind` / `agentId` / `to` / `from` filters and `pagination.hasMore`, fetch one call (agent-handled calls carry `transcript`), end a ringing or active call, and fetch the recording (`url` and `expiresAt` are set only while `status` is `ready`; the signed URL lasts five minutes). Reads need an API key with the `calls:read` scope, writes `calls:write` and a live key. Both POSTs carry an `Idempotency-Key` and accept your own. Calls are prepaid per started minute (10 credits a minute for an agent-handled outbound call) to US and Canadian numbers; until voice is enabled for your account the endpoints throw `NotFoundException` (`voice_not_enabled`). `CallStatus`, `CallDirection`, `CallKind`, `CallHandledBy`, `CallBilling`, `CallRecordingStatus` and `CallErrorCode` hold the string values the endpoints use; a 402 `insufficient_credits` arrives as `InsufficientCreditsException`, a 428 `e911_required` or 409 `lines_busy` as `SendlyException` with `getCode()` and `getApiErrorCode()` set.

### Patch Changes

- **4xx responses are no longer retried.** The client used to retry every status it had no typed exception for, so a 409 `lines_busy`, 428 `e911_required`, 403 `live_key_required` or 409 `rcs_field_locked` went through the full backoff (three more attempts, about seven seconds) before the `SendlyException` reached you. Any 4xx now throws at once; connection failures, timeouts and 5xx responses retry as before. If you were passing `'maxRetries' => 0` to get the refusal quickly, you can drop it.

## 4.0.0

**Upgrading from 3.40.0:** that release already contained the breaking changes below, published by mistake as a minor version. 4.0.0 carries them under the correct major. Relative to 3.40.0, the only new changes are under **Security**.

### Breaking Changes

- **`WebhookEvent` exposes `data.object` verbatim, and stops pretending every event is a message.** `parseEvent()` used to force `data.object` through `WebhookMessageData` whatever the event type, which is only right for `message.*`. Every other event — `rcs_*`, `whatsapp_*`, `call.*`, `brand.*`, `campaign.*`, `assignment.*`, `number.*`, `port*`, `contact*`, `conversation.*`, `draft.*`, `verification.*` and anything added after this release — came back as a message with its real fields dropped and message defaults in their place, so an RCS agent id, a call's timestamps or a port request id were simply unreachable.

  `WebhookEvent::$object` is now the decoded `data.object` as an associative array, present for every event type, with keys and values exactly as they arrived. `WebhookEvent::$data` is the message view and is **`?WebhookMessageData`**: null for every non-`message.*` event. Code that read `$event->data->to` on a lifecycle event was reading an invented value; it must now branch on `$event->data !== null` or read `$event->object`. `WebhookEvent::isMessageEvent()`, `WebhookEvent::get()`, `WebhookEvent::objectAs()` and `WebhookEvent::verification()` are new.

- **`WebhookMessageData` no longer invents field values, and every property is nullable.** `to` and `from` defaulted to `''`, `segments` to `1`, `credits_used` to `0` and `direction` to `'outbound'` when the payload carried none of them, which is indistinguishable from a real send of one segment to an empty number. A field the event did not carry is `null` now. `id`, `status`, `to`, `from` and `direction` are `?string`; `segments` and `creditsUsed` are `?int`. `getMessageId()` returns `?string`.

- **A JSON `null` stays null.** `call.*` events legitimately carry `from` and `to` as null — that is every in-app call — and those used to arrive as `""`. `$event->object` and the message view both preserve null.

- **`contact.auto_flagged` no longer reports the contact id as the message id.** The payload's `id` is the contact and its `message_id` is the message that flagged it; the old decoder read `id ?? message_id`, so a handler that acted on `$event->data->id` acted on the wrong row. Contact events have no message view at all now, and both ids are readable on `$event->object`.

- **`WebhookVerificationData` is wired up rather than dead.** Reach it with `$event->verification()` on a `verification.*` event, or `$event->objectAs(WebhookVerificationData::class)`. Its fields are nullable and it no longer defaults `delivery_status` to `'queued'`, `attempts` to `0` or `max_attempts` to `3`; its constructor arguments all default to null.

### Migration

Every handler that reaches through `$event->data` on a non-`message.*` event needs one
edit. Take `rcs_agent.live`, whose `data.object` is
`{"agent_id": "...", "name": "...", "stage": "live", "organization_id": "..."}`.

**What your code does today, on 3.x:**

```php
$event = Webhooks::parseEvent($raw, $signature, $secret, $timestamp);

if ($event->type === 'rcs_agent.live') {
    activateAgent($event->data->id);      // '' — the payload has no `id`; the agent id was dropped
    audit($event->data->direction);       // 'outbound' — invented, this event has no direction
    meter($event->data->creditsUsed);     // 0 — invented, this event bills nothing
}
```

Nothing raised, nothing was logged, and `$event->data` was a fully populated
`WebhookMessageData` whose every value was a default rather than anything the event
carried. `activateAgent('')` ran against an empty id.

**What the same code does on 4.0.0:** `$event->data` is `null` here, so
`$event->data->id` raises `Warning: Attempt to read property "id" on null` and
evaluates to `null`; under an error handler that promotes warnings to exceptions
(Laravel does by default, Symfony in debug mode) that is a hard failure, and
`$event->data->getMessageId()` is an outright
`Error: Call to a member function getMessageId() on null`. **That is the intended
behaviour, not an oversight.** There is no correct message field to hand back
for an event that carries no message, and the empty string it used to return was a
wrong answer wearing the costume of a right one. Failing where the old release invented
a value is the whole point of the major.

**The edit:** read the lifecycle payload off `$event->object`, or `$event->get()`.

```php
if ($event->type === 'rcs_agent.live') {
    activateAgent($event->object['agent_id']);
    audit($event->get('stage'));
}
```

Three more edits worth making at the same time:

- **Guard message handlers.** `$event->data` is `?WebhookMessageData`, so branch on
  `if ($event->data !== null)` or use `$event->data?->id`. `WebhookEvent::isMessageEvent($event->type)`
  answers the same question ahead of the read.

- **`contact.auto_flagged` was reporting the wrong record.** `$event->data->id` gave you
  the *contact* id under a name that says message, so a handler keyed on it acted on the
  wrong row. Both ids are on the raw object now, correctly named:

  ```php
  // Before: $event->data->id  — the contact id, called a message id
  quarantine($event->object['id']);          // contact
  reviewMessage($event->object['message_id']); // the message that flagged it
  ```

- **Message fields are nullable now.** `$event->data->to`, `->from`, `->id`, `->status`
  and `->direction` are `?string`, and `->segments` and `->creditsUsed` are `?int`. Code
  that passes them straight into a `string` or `int` parameter can now hit a `TypeError`
  where it previously received `''`, `1`, `0` or `'outbound'`. That absence was always
  there; only its visibility is new. Coalesce at your boundary if you need a value:
  `$event->data->to ?? throw new RuntimeException('no recipient on ' . $event->type)`.

### Minor Changes

- **`whatsapp_template.*` timestamps survive.** The server sends `createdAt` and `updatedAt` on those events in camelCase as ISO-8601 strings, and the old snake_case-only decoder dropped both. They are on `$event->object` verbatim, and the message view now reads the camelCase spelling of `created_at`, `delivered_at`, `failed_at`, `organization_id`, `error_code`, `credits_used`, `message_format`, `media_urls`, `retry_count` and `batch_id` as a fallback.

- **`message.queued` and `message.undelivered` are not subscribable, and never were from this SDK.** The API emits neither, and rejects both with a `400` when you subscribe. PHP has never listed them among the `Webhook::EVENT_*` constants, so nothing was removed here; if you pass either string in a hand-written `events` array to `$client->webhooks()->create()` or `update()`, drop it or the whole call fails.

- **Both decode styles are accepted.** `WebhookEvent` and `WebhookMessageData` take an array or an object, so an envelope decoded with `json_decode($body)` and one decoded with `json_decode($body, true)` behave the same. `parseEvent()` itself decodes into arrays now.


- **RCS registration over the API.** `$client->rcs` gains `registration->get()`, `dossier->get()`, `brands->create()` / `update()`, and `agents->create()` / `get()` / `update()` / `setTestDevices()` / `submit()` / `requestLaunch()`, mirroring the dashboard's RCS registration flow: draft a brand and an agent, submit them for review (Sendly first, then the carrier network), invite test devices, and request launch. Reads need an API key with the `rcs:read` scope, writes `rcs:write`. Logo, hero and call-to-action media must be public `https://` URLs; file upload stays dashboard-only. `agents->list()` rows now carry `stage`. The channel is still rolling out: while it is off for your account these endpoints throw `NotFoundException` (`rcs_not_enabled`). `RcsCustomerStage`, `RcsReviewStatus` and `RcsErrorCode` hold the string values the endpoints use.

- **`SendlyException::getApiErrorCode()`** returns the API's `error` code (`rcs_field_locked`, `insufficient_permissions`, ...) next to the message, on every typed exception. `ValidationException::getDetails()` now also carries the API's `errors` list (`[{path, message}]`) when a response has one instead of `details`.


### Security

- **Path parameters are percent-encoded.** Every id you pass is now encoded (`rawurlencode`) before it goes into the request path. An id containing `/`, `?` or `#` used to change which endpoint the request reached: an id of `../../account/keys` left its collection and hit another endpoint carrying your API key. Ordinary ids are sent byte-for-byte as before.
- **Guzzle's floor is raised to 7.15.2** (`guzzlehttp/psr7` to 2.12.3), clearing nine advisories, including CVE-2026-69246 (a noncanonical host could bypass host-based checks) and CVE-2026-55568 (a silent HTTPS proxy downgrade to cleartext). If your application pins an older Guzzle 7, Composer will ask you to update it. Guzzle 8 is not supported yet: it moves `getResponse()` off `RequestException`.

## 3.38.0

### Minor Changes

- **Every call made through a resource method now reaches the API. Before this release, none of them did.** The Guzzle client was built with `https://sendly.live/api/v1` as its base URI while the resources issued an absolute path such as `/messages`, and URL resolution replaces the entire base path for an absolute-path reference. So `$client->messages->send(...)` went to `https://sendly.live/messages`, which is served by the web app, not the API. The HTML that came back decoded to nothing and the SDK's `?? []` fallback turned that into an empty result without raising. The URL is now composed in the transport layer, so calls land on the versioned API.

  **Read your integration before upgrading.** Calls that were inert will now really execute. `send()` returned a `Message` with every field null and no message was ever sent; it now sends and charges credits. The same goes for every write in the SDK: contacts imported, campaigns created, numbers ordered, keys revoked, webhooks registered. Errors also surface for the first time. A bad API key, an out-of-credits account, an invalid payload or an unknown ID now raise `AuthenticationException`, `InsufficientCreditsException`, `ValidationException` and `NotFoundException` where the SDK previously returned an empty array and carried on.

- **Calls that spell out the versioned path themselves are unaffected, and are not prefixed twice.** If you have been reaching endpoints with no resource method by calling `$client->get('/api/v1/conversations', ['limit' => 100])` directly, those requests were already resolving correctly and still resolve to exactly the same URL. The short form `$client->get('/conversations', ...)` now resolves there too. A custom `baseUrl` given with or without a trailing slash behaves identically.

- **Automatic idempotency keys on POST.** Every POST the SDK makes, including multipart uploads such as `$client->media->upload(...)`, now carries an `Idempotency-Key` header generated per logical request and reused across the SDK's own retry attempts. If a send times out or the connection drops after the server already received it, the retry is recognised and you get the original response back instead of a second message. The server records a key only once the first attempt has finished, so this narrows the duplicate-send window rather than closing it: a retry that fires while the original is still running is not seen as a repeat. GET, PATCH, PUT and DELETE are untouched.

  Two details worth knowing. On a 5xx the auto-generated key is rotated before retrying, because the server responded so the outcome is known and the retry should be a fresh attempt; the server does not record a 5xx against a key either; on a timeout or connection failure the key is kept, because the outcome is unknown. Keys generated by the SDK live only for the duration of one call, so they do not protect you across process restarts or your own retry loop.

- **Supply your own idempotency key when you need protection across processes.** `send()`, `sendGroup()`, `schedule()` and `sendBatch()` accept one, as do the WhatsApp and RCS branches of `send()`:

  ```php
  // Positional
  $client->messages->send('+15551234567', 'Hello!', null, null, null, null, 'order-1042-confirm');

  // Options array
  $client->messages->send([
      'to'             => '+15551234567',
      'text'           => 'Hello!',
      'idempotencyKey' => 'order-1042-confirm',
  ]);
  ```

  Repeating a request with the same key within 24 hours returns the original response instead of executing again. Reusing a key with a different body is rejected rather than silently deduped. A caller-supplied key is sent verbatim and is never rotated, including across a 5xx retry. Keys must be 1 to 255 printable ASCII characters; anything longer or non-ASCII raises `ValidationException` before a request is made, and an empty or whitespace-only value is treated as absent so auto-generation still applies.

- **`sendBatch()` deliberately does not get an automatic key.** The batch endpoint dedupes header-less retries server-side by hashing the request content, which also catches an identical batch replayed from a different process. An auto-generated key would bypass that. Pass `idempotencyKey` explicitly if you want a key of your own on a batch.

### Patch Changes

- **`$client->account->transactions()` now returns your credit history.** It requested `/account/transactions`, a path the API does not serve. Combined with the URL bug it returned an empty array forever. It now calls the credit history endpoint that the Node, Python and Ruby SDKs already use.

- **`$client->account->revokeApiKey()` now revokes the key.** It issued `DELETE /account/keys/{id}`, a path registered for GET only, so the call could never have taken effect. Revocation is a `PATCH` to `/account/keys/{id}/revoke` and that is what the method sends. It still returns `true`, but the key is genuinely revoked now, so do not call it against a key you are still using.

- **`$client->account->apiKeys()` now returns your API keys.** On the published release it returned an empty array, because no request reached the API. Fixing the URL alone would not have been enough: the list comes back under a `keys` envelope that the response unwrapping did not recognise, which would have mapped the whole response into one malformed `ApiKey`. The envelope is now read correctly, with the older shapes still accepted as fallbacks.

### Endpoints that still do not exist

Because failures used to be swallowed, three methods that could never have worked returned an empty or all-null value rather than failing. Now that requests reach the API they raise `NotFoundException`, which will look like a new failure but is the same old gap made visible:

- `$client->templates->clone()`: the clone route exists only on the unversioned, session-authenticated path, so it is not reachable with an API key.
- `$client->webhooks->getDelivery()` and `$client->webhooks->retryDelivery()`: no per-delivery route is registered at any version, the deliveries endpoint is list-only. To replay failed deliveries use `$client->webhooks->redeliver($webhookId)`, which does work.


## 3.33.0

### Minor Changes

- **`Contacts::import()`** (`$client->contacts->import(...)`) — bulk-import contacts in one call, mirroring the Node SDK's `contacts.import()` and the Ruby SDK's `import_contacts`. Pass an array of contact items (each with at least a `phone` in E.164) plus optional `listId` and batch-wide `optedInAt`:

  ```php
  $result = $client->contacts->import([
      ['phone' => '+15551234567', 'name' => 'Jane Doe', 'email' => 'jane@example.com'],
      ['phone' => '+15559876543', 'optedInAt' => '2024-01-01T12:00:00Z'],
  ], [
      'listId'    => 'lst_abc',
      'optedInAt' => '2024-01-01T00:00:00Z',
  ]);
  // ['imported' => 2, 'skippedDuplicates' => 0, 'errors' => [], 'totalErrors' => 0]
  ```

- **`Conversations::suggestReplies()`** (`$client->conversations->suggestReplies($id)`) — generate AI-suggested replies for a conversation, mirroring the Node SDK's `conversations.suggestReplies()`:

  ```php
  $result = $client->conversations->suggestReplies('cnv_abc');
  foreach ($result['suggestions'] as $s) {
      echo $s['text'] . PHP_EOL;
  }
  ```

## 3.32.0

### Minor Changes

- **New `BusinessUpgrade` resource** (`$client->businessUpgrade`) — port of the Node SDK resource for the toll-free entity-upgrade ("fork-with-new-number") flow. When a customer forms a new legal entity (e.g. an LLC), this resource reserves a new toll-free number under the new entity, submits it for carrier review, and atomically swaps to it on approval — without disrupting outbound SMS during the 1-2 week review window.

  ```php
  // Preview validation before submitting (no writes).
  $preview = $client->businessUpgrade->preflight([
      'businessName' => 'Acme Holdings LLC',
      'brn'          => '12-3456789',
      'brnType'      => 'EIN',
      'brnCountry'   => 'US',
      'entityType'   => 'PRIVATE_PROFIT',
  ]);

  // Submit the upgrade with the IRS letter.
  $result = $client->businessUpgrade->start('ws_abc', [
      'businessName' => 'Acme Holdings LLC',
      'brn'          => '12-3456789',
      'brnType'      => 'EIN',
      'brnCountry'   => 'US',
      'entityType'   => 'PRIVATE_PROFIT',
  ], [
      'einDocPath' => __DIR__ . '/CP-575.pdf',
  ]);

  // Check status, cancel, resubmit, or set old-number disposition.
  $client->businessUpgrade->status('ws_abc');
  $client->businessUpgrade->cancel('ws_abc');
  $client->businessUpgrade->resubmit('ws_abc', ['website' => 'https://acme.com']);
  $client->businessUpgrade->setDisposition('ws_abc', [
      'disposition'       => 'moved',
      'targetWorkspaceId' => 'ws_xyz',
  ]);
  ```

  The EIN doc is uploaded as a multipart `einDoc` field via Guzzle's `multipart` request option. Pass either `einDocPath` (file path) or `einDocContents` (raw bytes / opened stream + `einDocFilename`).

- **`VERSION` constant updated to `3.32.0`** — previously the constant was stale (`1.0.6`) while Composer reported `3.31.0`. The two are now aligned, so `Sendly::VERSION` matches the published package version.

## 3.31.0

### Patch Changes

- **Resource accessors are now public properties (backward-compatible).** Idiomatic PHP usage matches our Node/Python/Ruby SDKs and our published docs:

  ```php
  $client->messages->send('+1...', 'hello');
  $client->labels->create(...);
  $client->enterprise->workspaces->create(...);
  ```

  The legacy method-style accessors (`$client->messages()`, `$client->webhooks()`, …) continue to work unchanged, so existing v1.0.5 code keeps running on upgrade.

- **`Messages::send()` now accepts an options array** in addition to its existing positional signature. Both calling conventions produce the same result:

  ```php
  // Positional (existing)
  $client->messages->send('+1...', 'hello', 'transactional');

  // Array of options (new — matches our Node/Python/Ruby SDKs)
  $client->messages->send([
      'to' => '+1...',
      'text' => 'hello',
      'messageType' => 'transactional',
  ]);
  ```

- **README fix:** the Enterprise "Quick Provision" example incorrectly referenced `Sendly\Client` (which does not exist). Corrected to `use Sendly\Sendly;` + `new Sendly(...)`.

### Why this release

A customer hit fatal `Cannot access private property` errors copy-pasting code from our docs because the docs assumed property access (the idiom of our other SDKs) while the PHP SDK exposed only method accessors. Making the properties public and accepting an array on `send()` reconciles the SDK with our docs without breaking existing PHP-style consumers.

## 3.30.0

### Minor Changes

- `$sendly->enterprise->workspaces->submitVerification($workspaceId, $data)`: rewritten to match the actual API shape (camelCase top-level, nested `address`/`contact` arrays, `entityType` + `brn`/`brnType`/`brnCountry` instead of `businessType`/`ein`). The previous shape didn't match the server endpoint and was returning 400s.
- **Partial-update friendly:** for resubmits on existing workspaces, send only the fields you want to change — everything else is filled from the existing record. Hosted page URLs (`/biz/`, `/opt-in/`, `/legal/`) generated during provision are auto-preserved. Null values in the input array are filtered out before sending.
- `$sendly->enterprise->workspaces->resubmitVerification($workspaceId, $partialUpdates)`: convenience alias for resubmits — same as `submitVerification` but reads more naturally for one-field-change use cases.

### Server-side fixes paired with this release

- `/api/v1/enterprise/workspaces/:id/verification/submit` now returns specific missing-field errors (e.g. `"Missing required fields: website"`) instead of listing every required field whether present or not.
- Endpoint accepts both flat and `{ verification: {...} }` wrapped shapes (matches `/enterprise/provision`).
- `useCase` validation expanded from 23 entries to the full 43-value carrier use-case enum.

## 3.29.0

### Minor Changes

- `$sendly->contacts->bulkMarkValid(['ids' => [...]])` / `['listId' => '...']`: clear the invalid flag on many contacts at once (up to 10,000 per call). Escape hatch for when auto-mark misclassifies at scale.
- Four new list-health `Webhook` event constants: `EVENT_CONTACT_AUTO_FLAGGED`, `EVENT_CONTACT_MARKED_VALID`, `EVENT_CONTACTS_LOOKUP_COMPLETED`, `EVENT_CONTACTS_BULK_MARKED_VALID`.
- New `Sendly\ListHealthEventSource` class with frozen constants (`SEND_FAILURE | CARRIER_LOOKUP | USER_ACTION | BULK_MARK_VALID`) for the `source` field on auto-flag and mark-valid webhooks.
- `Contact` responses gain `user_marked_valid_at` — when a user manually cleared an auto-flag. Carrier re-checks respect this timestamp and leave the contact clean.

## 3.28.0

### Minor Changes

- `$sendly->contacts->markValid($id)`: clear the auto-exclusion flag on a contact.
- `$sendly->contacts->checkNumbers(['listId' => ..., 'force' => ...])`: trigger a background carrier lookup.

## 3.18.1

### Patch Changes

- fix: webhook signature verification and payload parsing now match server implementation
  - `verifySignature()` accepts optional `?string $timestamp` for HMAC on `timestamp.payload` format
  - `parseEvent()` handles `data->object` nesting (with flat `data` fallback for backwards compat)
  - `WebhookEvent` adds `bool $livemode`, `int|string $created` fields
  - `WebhookMessageData` renamed `$messageId` to `$id` (with `getMessageId()` deprecated alias)
  - Added `$direction`, `$organizationId`, `$text`, `$messageFormat`, `$mediaUrls` fields
  - `generateSignature()` accepts optional `$timestamp` parameter
  - 5-minute timestamp tolerance check prevents replay attacks

## 3.18.0

### Minor Changes

- Add MMS support for US/CA domestic messaging
- Add `mediaUrls` parameter on `messages->send()` for sending MMS

## 3.17.0

### Minor Changes

- Add structured error classification and automatic message retry
- New `errorCode` field with 13 structured codes (E001-E013, E099)
- New `retryCount` field tracks retry attempts
- New `retrying` status and `message.retrying` webhook event

## 3.16.0

### Minor Changes

- Add `transferCredits()` for moving credits between workspaces

## 3.15.2

### Patch Changes

- Add metadata support to Message class and batch operations

## 3.13.0

### Minor Changes

- Campaigns, Contacts & Contact Lists resources with full CRUD
- Template clone method
