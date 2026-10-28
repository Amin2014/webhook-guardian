<?php

declare(strict_types=1);

namespace WebhookGuardian;

use WebhookGuardian\Exception\InvalidSignature;
use WebhookGuardian\Exception\TimestampOutOfRange;

/** Implements this package's protocol, not any third-party provider protocol. */
final class Verifier
{
    /** @var non-empty-list<non-empty-string> */
    private readonly array $secrets;

    /** @param non-empty-list<non-empty-string> $secrets Current secret followed by retained rotation secrets. */
    public function __construct(
        array $secrets,
        private readonly int $toleranceSeconds = 300,
        private readonly Clock $clock = new SystemClock(),
    ) {
        if ($secrets === [] || !array_is_list($secrets) || count($secrets) > 16) {
            throw new \InvalidArgumentException('Provide between 1 and 16 secrets as a list.');
        }
        foreach ($secrets as $secret) {
            if (!is_string($secret) || $secret === '') {
                throw new \InvalidArgumentException('Secrets must be non-empty strings.');
            }
        }
        if ($toleranceSeconds < 0) {
            throw new \InvalidArgumentException('Tolerance cannot be negative.');
        }
        $this->secrets = $secrets;
    }

    /** Authenticate exact bytes and return the authenticated Unix timestamp. No persistence occurs. */
    public function verify(string $rawBody, string $signatureHeader): int
    {
        // Canonical grammar: no whitespace, duplicate fields, multiple signatures, or alternate encodings.
        if (strlen($signatureHeader) > 90 || preg_match(
            '/\At=(0|[1-9][0-9]{0,18}),v1=([a-f0-9]{64})\z/D',
            $signatureHeader,
            $parts,
        ) !== 1) {
            throw new InvalidSignature('Malformed signature header.');
        }
        $timestampText = $parts[1];
        $max = (string) PHP_INT_MAX;
        if (strlen($timestampText) > strlen($max)
            || (strlen($timestampText) === strlen($max) && strcmp($timestampText, $max) > 0)) {
            throw new InvalidSignature('Timestamp is not representable.');
        }
        $matched = false;
        foreach ($this->secrets as $secret) {
            $expected = hash_hmac('sha256', $timestampText . '.' . $rawBody, $secret);
            // Evaluate every retained key; never short-circuit verification on the matching key.
            $equal = hash_equals($expected, $parts[2]);
            $matched = $equal || $matched;
        }
        if (!$matched) {
            throw new InvalidSignature('Signature does not match.');
        }
        $timestamp = (int) $timestampText;
        $now = $this->clock->now();
        if ($now < 0) {
            throw new \LogicException('Clock returned a negative Unix timestamp.');
        }
        // Both are non-negative, so subtraction cannot overflow.
        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            throw new TimestampOutOfRange('Signed timestamp is outside the accepted window.');
        }
        return $timestamp;
    }
}
