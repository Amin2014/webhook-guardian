<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/receive.php';

use WebhookGuardian\{Guardian, Verifier};
use WebhookGuardian\Store\PdoSqliteEventStore;
use function WebhookGuardian\Examples\receive;

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout=5000');
$store = new PdoSqliteEventStore($pdo);
$store->install(); // Deploy-time setup in a real application.
$pdo->exec('CREATE TABLE example_outbox (event_id TEXT PRIMARY KEY, body BLOB NOT NULL)');
$secret = random_bytes(32); // Shared sender/receiver secret, loaded from a secret manager in production.
$guardian = new Guardian(new Verifier([$secret], toleranceSeconds: 300), $store, 'demo-account');
$rawBody = '{"id":"evt_demo","type":"payment.received","amount":1200}';
$timestamp = time();
$signature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
$header = 't=' . $timestamp . ',v1=' . $signature;

foreach ([1, 2] as $attempt) {
    $delivery = receive($pdo, $guardian, $rawBody, $header);
    echo 'Delivery ' . $attempt . ': ' . $delivery->claim->value . PHP_EOL;
}
$count = $pdo->query('SELECT COUNT(*) FROM example_outbox');
if ($count === false) {
    throw new RuntimeException('Cannot read outbox count');
}
echo 'Outbox rows: ' . $count->fetchColumn() . PHP_EOL;
