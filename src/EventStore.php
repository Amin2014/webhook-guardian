<?php

declare(strict_types=1);

namespace WebhookGuardian;

use WebhookGuardian\Exception\PayloadConflict;

interface EventStore
{
    /**
     * Atomically reserve (scope, eventId) with its SHA-256 raw-body fingerprint.
     * Return Accepted only for the insertion winner; Duplicate for the same fingerprint.
     * Never replace an existing record. Throw on storage failure (fail closed).
     * Records must remain immutable and present for the entire replay retention window.
     * Join the caller's transaction if supported; never commit it inside this method.
     *
     * @throws PayloadConflict Same scoped ID with different bytes.
     */
    public function claim(string $scope, string $eventId, string $bodyHash, int $signedAt): Claim;
}
