<?php

declare(strict_types=1);

namespace WebhookGuardian\Store;

use PDO;
use WebhookGuardian\Claim;
use WebhookGuardian\EventStore;
use WebhookGuardian\Exception\PayloadConflict;

/** SQLite >= 3.24.0. Uses the caller's connection and transaction. */
final readonly class PdoSqliteEventStore implements EventStore
{
    public function __construct(private PDO $pdo)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \InvalidArgumentException('This adapter requires PDO SQLite.');
        }
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new \InvalidArgumentException('Configure PDO::ERRMODE_EXCEPTION.');
        }
    }

    /** Run once during deployment, outside request transactions. */
    public function install(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Install the schema outside a transaction.');
        }
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS webhook_guardian_events (
                scope TEXT NOT NULL,
                event_id TEXT NOT NULL,
                body_hash TEXT NOT NULL CHECK(length(body_hash) = 64),
                signed_at INTEGER NOT NULL,
                PRIMARY KEY (scope, event_id)
            ) WITHOUT ROWID
            SQL);
    }

    public function claim(string $scope, string $eventId, string $bodyHash, int $signedAt): Claim
    {
        if ($scope === '' || strlen($scope) > 255 || $eventId === '' || strlen($eventId) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $eventId) === 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $bodyHash) !== 1 || $signedAt < 0) {
            throw new \InvalidArgumentException('Invalid event record.');
        }
        $insert = $this->pdo->prepare(<<<'SQL'
            INSERT INTO webhook_guardian_events (scope, event_id, body_hash, signed_at)
            VALUES (:scope, :id, :hash, :signed_at)
            ON CONFLICT(scope, event_id) DO NOTHING
            SQL);
        $insert->execute(['scope' => $scope, 'id' => $eventId, 'hash' => $bodyHash, 'signed_at' => $signedAt]);
        $inserted = $insert->rowCount() === 1;
        $insert->closeCursor();
        if ($inserted) {
            return Claim::Accepted;
        }
        $select = $this->pdo->prepare(
            'SELECT body_hash FROM webhook_guardian_events WHERE scope = :scope AND event_id = :id',
        );
        $select->execute(['scope' => $scope, 'id' => $eventId]);
        $existing = $select->fetchColumn();
        $select->closeCursor();
        if (!is_string($existing)) {
            throw new \RuntimeException('Event record disappeared; retention contract was violated.');
        }
        if (!hash_equals($existing, $bodyHash)) {
            throw new PayloadConflict('Event ID was already reserved with a different payload.');
        }
        return Claim::Duplicate;
    }
}
