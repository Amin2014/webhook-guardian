# webhook-guardian

A small, framework-independent PHP 8.3+ library for authenticating exact webhook
bytes and atomically deduplicating deliveries. No HTTP server, callback runner,
queue, or provider SDK. MIT licensed.

## Install and run

Requires PHP 8.3+ and Composer. The SQLite adapter also requires `ext-pdo`,
`ext-pdo_sqlite`, and SQLite 3.24+. Core verification has no runtime dependencies.

```sh
# From the source checkout:
composer install
composer test
composer analyse
php examples/demo.php
```

Expected demo output: `Delivery 1: accepted`, `Delivery 2: duplicate`,
`Outbox rows: 1`. The Composer package name is
`webhook-guardian/webhook-guardian`. This checkout is ready to publish; no
Packagist publication is implied. Once published, consumers can use
`composer require webhook-guardian/webhook-guardian`.

## Protocol: explicitly custom

This is **not a Stripe, GitHub, or other provider signing implementation**.
Only use it when you control the sender or the sender implements this exact
protocol. A valid signature from a different protocol is not interchangeable.

One transport header (recommended name: `X-Webhook-Signature`) has this grammar:

```text
t=<timestamp>,v1=<signature>
```

* Timestamp: canonical non-negative decimal Unix seconds, no leading zeros
  except `0`, at most 19 digits, representable as a PHP integer.
* Signature: exactly 64 lowercase hexadecimal characters.
* Exactly one `t`, one `v1`, in that order. No whitespace, duplicate fields,
  extra fields, comma-joined headers, uppercase hex, or trailing newline.
* The transport layer must reject multiple occurrences of the header, including
  when a server would otherwise silently pick one. This library receives one
  string and cannot inspect the original HTTP header multiplicity.
* The signed message is the timestamp text, one ASCII dot, and the **exact raw
  body bytes**. There is no JSON normalization, decoding, newline trimming,
  decompression, character conversion, or base64 step.

Sender:

```php
$timestamp = (string) time();
$signature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
$header = 't=' . $timestamp . ',v1=' . $signature;
```

Timestamp and body are authenticated together. Every retained secret is checked
using `hash_equals`; matching does not short-circuit the key loop. The timestamp
is checked after signature verification. The inclusive acceptance window is
`abs(receiverTime - signedTime) <= toleranceSeconds` in both directions.
Default tolerance: 300 seconds; zero accepts only the current second. There is
no switch that disables timestamp checking. Synchronize sender/receiver clocks.

## Receiver API

```php
use WebhookGuardian\Guardian;
use WebhookGuardian\JsonEventId;
use WebhookGuardian\Verifier;
use WebhookGuardian\Store\PdoSqliteEventStore;

$pdo = new PDO('sqlite:/var/lib/my-app/webhooks.sqlite', options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('PRAGMA busy_timeout=5000');
$store = new PdoSqliteEventStore($pdo);
$store->install(); // Run at deployment time, not on every request.

$guardian = new Guardian(
    new Verifier([$currentSecret, $previousSecret], toleranceSeconds: 300),
    $store,
    scope: 'merchant-42:payments',
);

// Read php://input once. Enforce a request size limit before buffering it.
$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    throw new RuntimeException('Cannot read request body');
}
// $header is the single X-Webhook-Signature value from your HTTP adapter.
$delivery = $guardian->receive($rawBody, $header, new JsonEventId());
```

`Verifier::verify(body, header): int` authenticates without touching storage and
returns the signed timestamp. `Guardian::receive(body, header, extractor)` first
authenticates, then extracts and validates the ID, then reserves it. It returns
an immutable `Delivery` with `eventId`, `signedAt`, and `claim` (`Claim::Accepted`
or `Claim::Duplicate`). **Accepted means reserved, not processed.** The simple
call above autocommits the reservation; use the transaction pattern below for
reliable business processing.

No business callback is accepted or executed. The ID extractor runs only after
authentication and must be pure: it must derive a stable ID solely from the
supplied authenticated body, never from unsigned headers, URL parameters, or
other external state. The optional `JsonEventId` expects a top-level JSON object
with a string `id`; numeric IDs are rejected, and JSON depth is limited to 64.
PHP JSON decoding uses its usual last-key-wins behavior for repeated object keys;
use an unambiguous sender schema, and use the same parser for business processing.
A custom extractor supports other body formats. IDs are 1–255 bytes without ASCII
control characters; ID equality is exact, case-sensitive byte equality in SQLite.

Scope is a trusted receiver configuration (1–255 bytes), not an unsigned caller
choice. Use a different scope per sender/account/environment. Route to trusted
secrets before processing; signatures do not bind a URL, method, or destination.
Use different secrets across destinations unless deliberate cross-endpoint
replay sharing is intended.

## Outcomes and error mapping

| Outcome | Meaning | Typical HTTP policy chosen by the application |
| --- | --- | --- |
| `Claim::Accepted` | First reservation in this scope | 2xx only after business transaction commits |
| `Claim::Duplicate` | Same scoped ID and identical raw-body SHA-256 | 2xx after committed processing is established |
| `InvalidSignature` | Bad grammar or failed MAC | Generic 401/400, no processing |
| `TimestampOutOfRange` | Authentic MAC, outside time window | Generic 401/400, no processing |
| `InvalidEvent` | Authenticated but invalid ID/JSON | 400, no reservation |
| `PayloadConflict` | Same scoped ID, different raw bytes | 409 and investigate; do not process |
| Storage failure / `PDOException` | Reservation cannot be established | Roll back, return retriable 5xx |

`InvalidSignature` and `TimestampOutOfRange` extend `AuthenticationFailed`.
Configuration errors throw `InvalidArgumentException`; extractor exceptions
propagate. Never convert storage errors into duplicates or success. Responses
should not expose exceptions, secrets, signatures, or raw payment data.

A newly signed retry with identical bytes remains a duplicate. The same ID with
changed whitespace or JSON key order is a conflict even if logically equivalent.
The persisted fingerprint excludes timestamp/signature so ordinary resigning
does not bypass deduplication. A captured request replayed inside the time window
is a duplicate after the first committed reservation; outside the window it is
rejected before storage access.

## Transactions and business side effects

**Deduplication alone does not guarantee that business work happened.** In
autocommit mode a crash after reservation but before side effects loses the work:
future deliveries are duplicates. Do not use that pattern for payment processing.

For local database changes, use the **same PDO connection** for the store and
business statements, in **one transaction**:

1. Begin a transaction; make `receive()` the first database operation.
2. For `Accepted`, apply validated local domain changes and/or write an outbox
   row in this transaction. For `Duplicate`, skip those changes.
3. Commit. Only then acknowledge the HTTP delivery. On any error, roll back.

See [examples/receive.php](examples/receive.php) for a complete function and
[examples/demo.php](examples/demo.php) for a runnable caller. The store neither
starts nor commits nor rolls back your transaction. Schema installation is
explicit and refused inside a transaction.

SQLite's unique primary key on `(scope, event_id)` arbitrates concurrent claims.
The adapter attempts `INSERT ... ON CONFLICT(scope, event_id) DO NOTHING` first,
then reads the immutable fingerprint only when the insert loses. It never uses
an unsafe check-then-insert sequence or replaces an existing record. Lock errors,
timeouts, stale snapshot errors, disk errors, and constraint failures other than
the targeted unique key propagate. Configure a bounded `busy_timeout` per
connection; retry the **whole transaction** on appropriate lock failures. Avoid
reads before the claim in a deferred SQLite transaction. Use separate PDO
connections per concurrent request/process and a local filesystem supporting
SQLite locking; this is not a distributed database or NFS locking solution.

If the winning transaction rolls back, another delivery can reserve the event.
If it commits and the HTTP response is lost, the next delivery is a duplicate.
A database outage fails closed. Business validation failures must roll back the
reservation; decide separately how to handle permanently invalid signed events.

An external payment call, email, or database on another connection cannot be
atomically committed with this SQLite transaction. Use a **transactional outbox**
plus a separate dispatcher and destination idempotency keys. The dispatcher must
handle retries and crashes; this package does not implement it. Do not keep a
SQLite write transaction open during a network call. There is **no exactly-once
business effect guarantee**. Even an outbox can deliver more than once.

## Secret rotation

Generate high-entropy shared secrets (e.g. `random_bytes(32)`) and distribute them
securely; strings are used as literal bytes, not implicitly base64/hex decoded.
Configure `[current, previous]` at the receiver before switching the sender.
Retain previous keys for the required overlap, then remove them. One request
carries one signature; at most 16 receiver keys are allowed to bound key work.
Compromise of any retained secret permits valid new events until it is removed.
Do not put secrets in source control or logs.

## Persistence extension and retention

Implement `EventStore::claim(scope, eventId, bodyHash, signedAt): Claim` for another
backend. Its contract requires an atomic conditional insertion, immutable
fingerprints, exact scoped-ID equality, conflict detection, fail-closed storage
errors, and participation in caller transactions where supported. Run equivalent
concurrent, conflict, commit, and rollback tests for each adapter. A cache with
evictable keys or a process-local array is not an equivalent durable adapter.

Records are retained indefinitely by default; there is deliberately no automatic
expiry or delete API. Plan capacity and access control. `signed_at` is the first
sender timestamp, not a trusted receiver retention clock. If you add retention,
never delete or mutate active reservations or race cleanup with claims. Expiry
allows older IDs to be reused by a freshly signed request; document your chosen
business replay horizon. Keep an immutable business ledger where IDs must never
be reusable. Restoring an old database backup can also permit reprocessing.

## Threat model and limitations

Assumes high-entropy uncompromised secrets, SHA-256/HMAC security, trusted clocks,
correct HTTP raw-byte handling, trusted scope configuration, persistent protected
storage, and compliant store adapters. Protects against body/timestamp tampering,
unauthenticated submissions, stale signed requests, and duplicate processing
reservations for the same signed ID under concurrent requests.

It does not prevent a legitimate/compromised sender issuing new IDs for the same
logical action, first-use replay within the time window, denial of service,
endpoint misrouting when keys are shared, database loss, malicious administrators,
or semantic business fraud. MACs provide authentication, not confidentiality or
non-repudiation. Use HTTPS. `hash_equals` protects fixed-length MAC comparison;
parsing, body hashing, JSON processing, and database operations are not claimed
to be constant-time. Fingerprint equality relies on SHA-256 collision resistance.

Set request-body/header limits and rate limits at the HTTP boundary; this library
accepts an already-buffered string. Authenticate before trusting contents, but
still validate currency, amounts, account ownership, state transitions and other
domain rules before applying effects. This code has not undergone an independent
security audit.

## Development

```sh
composer validate --strict
composer install
composer check
find src tests examples -name '*.php' -print0 | xargs -0 -n1 php -l
php examples/demo.php
composer audit
```

PHPUnit covers fixed HMAC vectors, exact bytes, timestamp tampering and window
boundaries, key rotation, malformed headers, invalid IDs, replay/conflict handling,
persistence, rollback/retry, authentication ordering, and storage failures. Separate
PHP processes race on a file-backed WAL database, checking one reservation and one
transactional business effect, including competing payloads. Those tests require
`proc_open` and fail on worker errors; they skip explicitly if it is disabled.
PHPStan checks source and examples at the maximum level. CI targets PHP 8.3–8.5.

Protocol primitives: [PHP hash_equals](https://www.php.net/manual/en/function.hash-equals.php),
[PHP hash_hmac](https://www.php.net/manual/en/function.hash-hmac.php),
[SQLite UPSERT](https://www.sqlite.org/lang_upsert.html),
[SQLite transactions](https://www.sqlite.org/lang_transaction.html).
