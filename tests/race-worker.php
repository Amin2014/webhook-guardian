<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use WebhookGuardian\{Guardian, JsonEventId, Verifier};
use WebhookGuardian\Exception\PayloadConflict;
use WebhookGuardian\Store\PdoSqliteEventStore;

[$script, $path, $barrier, $variant] = $argv;
$pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA busy_timeout=10000');
$store = new PdoSqliteEventStore($pdo);
file_put_contents($barrier . '.' . getmypid() . '.ready', 'ready');
$deadline = microtime(true) + 15;
while (!is_file($barrier)) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timed out'); }
    usleep(1000);
}
$body = '{"id":"race","value":' . $variant . '}';
$timestamp = time();
$header = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, 'race-secret');
$guardian = new Guardian(new Verifier(['race-secret']), $store, 'race');
try {
    $pdo->beginTransaction();
    $delivery = $guardian->receive($body, $header, new JsonEventId());
    if ($delivery->claim === \WebhookGuardian\Claim::Accepted) {
        $pdo->exec("INSERT INTO effects VALUES ('race')");
        usleep(30000); // Keep winner's write lock long enough for overlapping attempts.
    }
    $pdo->commit();
    echo $delivery->claim->value;
} catch (PayloadConflict) {
    $pdo->rollBack();
    echo 'conflict';
}
