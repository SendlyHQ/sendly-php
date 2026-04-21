# sendly/sendly-php

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
