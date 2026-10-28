<?php

declare(strict_types=1);

namespace WebhookGuardian;

enum Claim: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
}
