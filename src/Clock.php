<?php

declare(strict_types=1);

namespace WebhookGuardian;

interface Clock
{
    /** Unix time in whole seconds. Must be non-negative. */
    public function now(): int;
}
