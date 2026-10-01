# sendly/sendly-php

## 4.3.0

### Minor Changes

- **Options and fields the API already supported, now in the PHP SDK.**
  - `account->createApiKey($name, ['type' => 'live', 'scopes' => [...]])`: `type` is `'test'` or `'live'`, and any other value throws a `ValidationException` before a request is made; `scopes` lists the scopes to grant, which must be scopes the calling key has. Without `type` the request body is unchanged and the API creates a test key. A live key needs a verified business and a credit balance; the API answers 403 `verification_required` or 402 `credits_required` otherwise. `expiresAt` is sent as before.
  - `enterprise->workspaces->inheritVerification($id, ['sourceWorkspaceId' => ..., 'purchaseNewNumber' => true])` copies only the verification details and orders the workspace its own toll-free number instead of sharing the source's. The result then carries `newNumber` true, even when no number could be ordered; `tollFreeNumber` is null in that case.
  - `enterprise->workspaces->provisionBulk()` accepts up to 100 workspaces, the API's limit. It refused more than 50 before sending anything.
  - `Account` gains `organization`, `credits` and `apiKey`. `credits` is the workspace's own `balance` and `reservedBalance`, as integers; a workspace on an enterprise credit pool spends from the pool, which `account->credits()` reports. `AccountVerification` gains `status`, `type`, `region`, `submittedAt`, `updatedAt` and `isVerified()`, and `AccountLimits` gains `messagesPerMinute`.
  - `Credits` gains `reservedBalance` and `billingMode` (`prepaid`, or `pooled` for a workspace on an enterprise credit pool).
  - `ApiKey` gains `type`, `scopes`, `revokedAt` and `isLive()`.
  - `Message` gains `simulated`, `simulatedReason` and `isSimulated()`, so a simulated send (a test key, a sandbox destination, or a live key the account is not yet set up to send with) can be told from a real one; and `messageFormat`, `mediaUrls` and `batchId`, read from the `message_format`, `media_urls` and `batch_id` keys the API sends.
  - `WebhookTestResult` gains `message` and `deliveryId`.
  - `Campaigns` gains `STATUS_COMPLETED`, what a sent campaign becomes, and `STATUS_FAILED`. `CreditTransaction` gains `TYPE_TRANSFER`, `TYPE_ADMIN_GRANT` and `TYPE_ADMIN_SEED`; auto-recharges are recorded as `TYPE_PURCHASE`. `CallErrorCode` gains `FROM_NUMBER_NOT_SUPPORTED`, the 400 `calls->create()` gets when the call would be placed from a number outside the US and Canada.

- **WhatsApp sender extras and adding a number by code.**
  - `whatsapp->senders` gains `uploadProfilePhoto($phoneNumber, $filePath, $contentType = null)` (a JPEG or PNG of at most 5 MB, sent as the multipart field `file`) and `deleteProfilePhoto($phoneNumber)`, both returning the profile; `getConversationalComponents($phoneNumber)` and `updateConversationalComponents($phoneNumber, ['iceBreakers' => [...], 'commands' => [...]])` for the ice breakers and "/" commands WhatsApp shows in a chat (each list you pass replaces the stored one, `[]` clears it, and passing neither throws a `ValidationException` before a request is made); and `setCalling($phoneNumber, $enabled)`, which switches WhatsApp calling on or off and returns `callingEnabled` and `outboundCallingAllowed`. Turning calling on needs voice on for the number (409 `voice_not_enabled`), and WhatsApp may refuse it (422 `whatsapp_calling_unavailable`). There is no API for placing WhatsApp calls.
  - `whatsapp->signup->create($phoneNumber, $options)` takes `businessAccountId`, `verificationMethod` (`sms` or `voice`) and `displayName` to add a number to a WhatsApp Business Account the workspace already connected, without the Facebook step. The signup comes back `verifying` with no `connectUrl`; the fee, refund and limits are the same. A `businessAccountId` option that is present but empty or whitespace-only throws `ValidationException` before anything is sent, instead of starting a paid Facebook signup. New `signup->verify($id, $code)` submits the 6-digit code WhatsApp sends, and `signup->resend($id, $verificationMethod = null)` asks for another (30 seconds apart; sooner is a `RateLimitException` with `getRetryAfter()`). A wrong code is a 422 `whatsapp_verification_code_invalid` whose `getResponseBody()` has `attemptsRemaining`. The client never retries `create()` with `businessAccountId`, `verify()` or `senders->uploadProfilePhoto()` after a 5xx or a failed connection, and throws at once instead: a 502 `whatsapp_verification_start_failed` has already failed and refunded the signup, so a retry would start, charge and refund another one; every `verify()` submission uses up one of the five attempts, and a 502 `whatsapp_activation_pending` means WhatsApp already accepted the code. These three calls also go out on a new connection, because cURL sends a request again by itself when a reused keep-alive connection closes before any response arrives. `resend()` and every other call keep the usual retries. Called without options, `create()` sends the same body and has the same return type as before.
  - A signup can be `verifying`, and while it is it carries `verificationMethod`, `verificationAttemptsRemaining` and, from `signup->get()`, `verificationCode` (the code once WhatsApp's text has reached the number, else null). New failure reasons: `verification_start_failed`, `verification_failed` and `verification_expired`.
  - Senders from `whatsapp->senders->list()` carry `businessAccountId`, `businessName` (both null while pending), `callingEnabled` and `outboundCallingAllowed` (false for every +1 number and for +20, +84 and +234 numbers).
  - Calls, and the call on the `call.started`, `call.completed` and `call.recording.ready` webhooks, carry `channel`: `phone`, `whatsapp` or `browser`. `CallChannel` holds the values.

### Patch Changes

- **What a failed WhatsApp send means.** A 502 `whatsapp_send_failed` means the message provably never reached the carrier, so it was not sent and is safe to send again; the client retries it under the same idempotency key as before. A new 409 `whatsapp_send_unconfirmed`, thrown as a `SendlyException`, means the outcome is unknown: the message was marked failed and refunded but may still be delivered, so check before sending it again (it could arrive twice). The client does not retry it.
- **A retried 5xx keeps its idempotency key.** After a 5xx the client sent the retry with a new auto-generated key, a leftover from when the API recorded server errors under the key. The API has not recorded a 5xx since August, so the retry runs again under the same key either way. A new key only lost protection in one case: when the API had finished the request and recorded its answer but a gateway returned the 5xx, a retry with a new key sent the message again. The retry now carries the same key, so that case returns the recorded answer instead. This applies to uploads too.
- **Uploads survive a retry.** A retried upload, after a 5xx or the busy key-check 429, failed on its second retry with Guzzle's `InvalidArgumentException: Invalid resource type: resource (closed)`, which is not a `SendlyException`: the first attempt closed the file handle when it was cleaned up. The file is now sent intact on every attempt. This affected `media()->upload()`, `enterprise()->uploadVerificationDocument()` and `businessUpgrade()->start()` and `resubmit()`.
- **The two 429s from API key checks, and the status of a 422.** A 429 whose API code is `too_many_concurrent_verifications` (too many first-time key checks running at once from one address) is now retried after its `Retry-After` (never a wait over a minute), with the same idempotency key, because the request never ran; this covers uploads too. The wait is the `Retry-After` itself, whichever attempt it follows, and a later retry in the same call, after a 5xx or a connection failure, waits its usual backoff. It was thrown on the first attempt. A 429 `too_many_failed_key_attempts` means repeated wrong API keys from one address locked the account out for a while. It is still thrown at once as a `RateLimitException`: `getApiErrorCode()` returns `too_many_failed_key_attempts` and `getRetryAfter()` says when the lockout ends. Fix the key, then wait, since until the lockout ends the right key can be refused too. A `ValidationException` from a 422 response now reports 422 from `getCode()`; it reported 400 for every validation error. `ValidationException` takes the status as an optional third constructor argument. `RateLimitException::getRetryAfter()` falls back to the body's `retryAfter` when there is no `Retry-After` header. It returned 0 for those, including `verify()->send()` against the per-phone or daily limit (up to 10 minutes or a day) and enterprise provisioning limits, so a caller that slept `getRetryAfter()` would have retried at once.
- **Methods that failed or never finished now work.** Each was checked against the handler it calls.
  - `account->createApiKey()` never sent `type`. The API used to require it, so every call failed with a 400 "Name and type are required"; the API now defaults a missing type to test, so PHP could only ever create test keys.
  - `contacts->lists()->each()` never finished for a workspace with exactly `batchSize` contact lists (100 by default): it yielded the same lists over and over. The API returns every list in one response and ignores `limit` and `offset`, so `each()` now reads them once.
  - `campaigns->delete()`, `contacts->delete()` and `contacts->lists()->delete()` returned `[]` after a successful delete, because the API answers 204 No Content, so `$result['success']` raised "Undefined array key". They return `['success' => true]`; an error response still throws.
- **Values that were wrong on every call.**
  - `account->get()` left `id` and `email` empty and set `createdAt` to the time the response was parsed, because the API nests them under `user`; they are read from there now. `isFullyVerified()` was false for every verified business; it is true once the business verification is approved. `maxBatchSize` was an invented 1,000; it is 10,000, the API's batch limit.
  - `Credits::$reservedCredits` was always 0: the API sends `reservedBalance`.
  - `MessageList::$hasMore` was always false, so `messages->each()` stopped after the first page: the API sends `hasMore`, not `has_more`.
  - With a `batchSize` over 100, `contacts->each()` and `campaigns->each()` stopped after 100 rows: a page holds at most 100, and they took a page shorter than `batchSize` for the last one. The offset also moved on by `batchSize`, more than a page, which would have skipped rows had a next page been read; `messages->each()` never read one because of the `hasMore` bug above. The offset now advances by the rows returned, the batch size is kept to 1-100, and contacts and campaigns stop at the `total` the API reports.
  - `webhooks->test()` reported `statusCode` 0 and `responseTimeMs` 0: the API nests them under `delivery`.
  - `Message::$errorMessage` was always null: the API sends a failed message's reason as `error`. `Message::$updatedAt` was the time the response was parsed; messages have no update time, so it is now `deliveredAt`, or `createdAt` before delivery.
  - `ApiKey` dropped the `type` and `scopes` every key endpoint sends, so `$key->type` raised "Undefined property".
  - Message and campaign text was refused as over 1600 characters when it was under that but written outside ASCII, because the check counted bytes: 1,000 accented letters are 2,000 bytes. It counts characters now, and text over 1600 characters still throws the same `ValidationException`.
- **An id of `.` or `..` is refused before any request is sent.** Percent-encoding leaves dots alone, and the HTTP client resolved such an id as a dot-segment, so the request reached a different endpoint: `enterprise->workspaces->revokeKey('ws_1', '..')`, `deleteOptInPage()` and `cancelInvitation()` sent `DELETE /enterprise/workspaces/ws_1/`, the route that deletes the workspace, and `contacts->lists()->removeContact('list_1', '..')` sent the request that deletes the list. Every method, and a path passed to `get()`, `post()`, `postMultipart()`, `put()`, `patch()` or `delete()`, now throws a `ValidationException` for a `.` or `..` path segment. `verify->get()`, `resend()` and `check()` also throw one for an empty id, which other methods already did; `verify->get('')` returned the list of verifications. An id with dots inside it, such as `key..1`, is sent as before.
- **A rule made from a list of conditions or actions now works.** `rules->create()` and `rules->update()` documented `$conditions` and `$actions` as a list of arrays, so code that followed the docblock sent JSON arrays. The API stores them as sent but reads only an object, so such a rule matched every message and applied nothing. A list of arrays is now merged into one object before it is sent, and the docblocks describe that object: `intent`, `sentiment`, `intentConfidenceMin` and `sentimentConfidenceMin` for conditions, `addLabels` and `closeConversation` for actions. An associative array is sent as before. A rule already saved as arrays stays that way until you update its conditions and actions.
- **Options that did nothing.** `campaigns->create()` and `update()` documented `segmentId` and `messageType`, but the API reads neither: every campaign is sent as a marketing message, subject to quiet hours, and a campaign targets one contact list. The docblocks say so and deprecate both options. The request is sent as before, and an invalid `messageType` still throws the `ValidationException` it did.
- **Docs that described behaviour the API does not have.** `webhooks->backfill()` no longer says synthesized events get fresh IDs or to dedupe on `data.object.id`: they carry the event id the original dispatch used, so dedupe on `event.id` (a message's sent and delivered events share `data.object.id`). `webhooks->test()` documents that a failed delivery throws a `ValidationException` whose `getResponseBody()` has `success` false, and that a webhook that is not found throws a `ValidationException` too, not the `NotFoundException` `webhooks->get()` throws. `Webhooks::parseEvent()` documents the `\JsonException` it throws for a correctly signed body that is not JSON. `contacts->lists()->list()` says the API does not apply `limit` or `offset`, and `contacts->lists()->each()` that `batchSize` has no effect. `campaigns->list()` documents the `total`, `limit` and `offset` it returns instead of a `pagination` key. `Campaigns::STATUS_SENT` is never returned, though as a `list()` filter it matches completed campaigns. These are deprecated because the API never sets or sends them: `Campaigns::STATUS_PAUSED`, `CreditTransaction::TYPE_ADJUSTMENT`, `Account::$name` and `$companyName`, `AccountVerification::$emailVerified`, `$phoneVerified` and `$identityVerified`, `Credits::$pendingCredits`, `AccountLimits::$messagesPerSecond` and `$maxBatchSize`, and `Message::$updatedAt`.
- **WhatsApp docs match the API.** The docblocks now say that the API never sends the `expired` signup status, that a closed window returns its past `expiresAt` rather than null, that a media send returns its caption as `text`, how in-window replies are priced (1 credit for the first 1,000 per sending number each month, then the destination's utility price), which roles and scopes connecting and editing need, the `waba_mismatch` and `registration_timeout` failure reasons, `template_header_variable_unsupported`, `whatsapp_unavailable` (503), `whatsapp_signup_limit_reached` (429), and `whatsapp_send_failed` as a final 422 or a retried 502. The template array shapes list the `body`, `header`, `footer` and `examples` keys the API returns. Nothing changes at runtime.

**Worth knowing before you upgrade.** No property, method or constant was removed or retyped, but several values change from a constant the SDK made up to what the API sent:

- `Account::$id`, `$email` and `$createdAt`, `Credits::$reservedCredits`, `Message::$errorMessage`, and `WebhookTestResult::$statusCode` and `$responseTimeMs` now carry the API's values. `Message::$updatedAt` is `deliveredAt`, or `createdAt` before delivery, instead of the time the response was parsed. `AccountVerification::isFullyVerified()` is true for a verified business. `AccountLimits::$maxBatchSize` reads 10,000 instead of 1,000.
- `messages->each()`, `contacts->each()` and `campaigns->each()` now read every page, so code that stopped early by accident makes more requests. A `batchSize` over 100 is treated as 100.
- `Message::toArray()` and `Credits::toArray()` carry the new fields.

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
