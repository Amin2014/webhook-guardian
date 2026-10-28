<?php

declare(strict_types=1);

namespace WebhookGuardian;

use WebhookGuardian\Exception\InvalidEvent;

/** Optional extractor for a top-level JSON object with a string "id" field. */
final class JsonEventId
{
    public function __invoke(string $rawBody): string
    {
        try {
            $event = json_decode($rawBody, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidEvent('Body must be valid JSON.', previous: $e);
        }
        if (!$event instanceof \stdClass || !isset($event->id) || !is_string($event->id)) {
            throw new InvalidEvent('Expected a JSON object with a string id.');
        }
        return $event->id;
    }
}
