<?php

declare(strict_types=1);

namespace Sendly\Resources;

/**
 * How the other party reached the call, as reported in `channel` on a call
 * and on the `call.started`, `call.completed` and `call.recording.ready`
 * webhooks: the phone network, WhatsApp, or a browser (a call between
 * teammates). An inbound WhatsApp call can read `PHONE` for now. More values
 * may be added, so treat one you don't know as a phone call rather than an
 * error.
 */
final class CallChannel
{
    public const PHONE = 'phone';
    public const WHATSAPP = 'whatsapp';
    public const BROWSER = 'browser';
}
