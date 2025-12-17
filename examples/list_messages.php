<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Sendly\Sendly;

$client = new Sendly(getenv('SENDLY_API_KEY') ?: 'sk_test_v1_example');

// List recent messages
echo "=== Recent Messages ===\n";
$messages = $client->messages()->list(['limit' => 10]);

echo "Total: {$messages->total}\n";
echo "Has more: " . ($messages->hasMore ? 'yes' : 'no') . "\n\n";

foreach ($messages as $msg) {
    echo "{$msg->id}: {$msg->to} - {$msg->status}\n";
}

// List with filters
echo "\n=== Delivered Messages ===\n";
$delivered = $client->messages()->list([
    'status' => 'delivered',
    'limit' => 5
]);

foreach ($delivered as $msg) {
    echo "{$msg->id}: Delivered at {$msg->deliveredAt?->format('Y-m-d H:i:s')}\n";
}

// Iterate all with auto-pagination
echo "\n=== All Messages (paginated) ===\n";
$count = 0;
foreach ($client->messages()->each(['batchSize' => 50]) as $msg) {
    echo "{$msg->id}: {$msg->to}\n";
    $count++;
    if ($count >= 20) break; // Limit for demo
}
