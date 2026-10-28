<?php

declare(strict_types=1);

namespace WebhookGuardian;

use WebhookGuardian\Exception\InvalidEvent;

final readonly class Guardian
{
    public function __construct(
        private Verifier $verifier,
        private EventStore $store,
        private string $scope,
    ) {
        if ($scope === '' || strlen($scope) > 255) {
            throw new \InvalidArgumentException('Scope must contain 1 to 255 bytes.');
        }
    }

    /**
     * The ID extractor is invoked ONLY after authentication. It must derive the ID
     * solely from the supplied signed body, never from unsigned request metadata.
     * No business callback is invoked by this library.
     *
     * @param callable(string): string $eventIdFromBody Pure ID extractor; may throw InvalidEvent.
     */
    public function receive(string $rawBody, string $signatureHeader, callable $eventIdFromBody): Delivery
    {
        $signedAt = $this->verifier->verify($rawBody, $signatureHeader);
        $id = $eventIdFromBody($rawBody);
        if ($id === '' || strlen($id) > 255 || preg_match('/[\x00-\x1f\x7f]/', $id) === 1) {
            throw new InvalidEvent('Event ID must contain 1 to 255 bytes without ASCII control characters.');
        }
        $claim = $this->store->claim($this->scope, $id, hash('sha256', $rawBody), $signedAt);
        return new Delivery($id, $signedAt, $claim);
    }
}
