<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Sendly\Sendly;
use Sendly\Exceptions\AuthenticationException;
use Sendly\Exceptions\InsufficientCreditsException;
use Sendly\Exceptions\RateLimitException;
use Sendly\Exceptions\ValidationException;
use Sendly\Exceptions\SendlyException;

// Create client
$client = new Sendly(getenv('SENDLY_API_KEY') ?: 'sk_test_v1_example');

try {
    // Send an SMS
    $message = $client->messages()->send(
        '+15125550123',
        'Hello from Sendly PHP SDK!'
    );

    echo "Message sent successfully!\n";
    echo "  ID: {$message->id}\n";
    echo "  To: {$message->to}\n";
    echo "  Status: {$message->status}\n";
    echo "  Credits used: {$message->creditsUsed}\n";
    if ($message->isSimulated()) {
        echo "  Simulated: " . ($message->simulatedReason ?? 'test key or sandbox number') . "\n";
    }
} catch (AuthenticationException $e) {
    echo "Authentication failed: {$e->getMessage()}\n";
} catch (InsufficientCreditsException $e) {
    echo "Insufficient credits: {$e->getMessage()}\n";
} catch (RateLimitException $e) {
    if ($e->getApiErrorCode() === 'too_many_failed_key_attempts') {
        echo "Locked out after repeated wrong API keys. Fix the key, then wait {$e->getRetryAfter()} seconds.\n";
    } else {
        echo "Rate limited. Retry after: {$e->getRetryAfter()} seconds\n";
    }
} catch (ValidationException $e) {
    echo "Validation error: {$e->getMessage()}\n";
} catch (SendlyException $e) {
    echo "Error: {$e->getMessage()}\n";
}
