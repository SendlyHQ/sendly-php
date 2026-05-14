# sendly/sendly-php

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
- `useCase` validation expanded from 23 entries to the full 43-value Telnyx enum.

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
