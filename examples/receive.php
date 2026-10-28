<?php

declare(strict_types=1);

namespace WebhookGuardian\Examples;

use PDO;
use WebhookGuardian\{Claim, Delivery, Guardian, JsonEventId};

/** The guardian's store MUST use this exact PDO connection. Schema is installed separately. */
function receive(PDO $pdo, Guardian $guardian, string $rawBody, string $signatureHeader): Delivery
{
    $pdo->beginTransaction();
    try {
        // First operation in the transaction: no earlier reads that could create a stale snapshot.
        $delivery = $guardian->receive($rawBody, $signatureHeader, new JsonEventId());
        if ($delivery->claim === Claim::Accepted) {
            // Local transactional outbox, not a network call. Apply domain changes here too.
            $statement = $pdo->prepare('INSERT INTO example_outbox (event_id, body) VALUES (:id, :body)');
            $statement->execute(['id' => $delivery->eventId, 'body' => $rawBody]);
        }
        $pdo->commit(); // Only acknowledge success after this returns.
        return $delivery;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
