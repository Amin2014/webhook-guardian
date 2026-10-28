<?php

declare(strict_types=1);

namespace WebhookGuardian;

final readonly class Delivery
{
    public function __construct(
        public string $eventId,
        public int $signedAt,
        public Claim $claim,
    ) {
    }
}
