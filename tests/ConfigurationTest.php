<?php

declare(strict_types=1);

namespace WebhookGuardian\Tests;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WebhookGuardian\{Clock, Guardian, Verifier};
use WebhookGuardian\Store\PdoSqliteEventStore;

final class ConfigurationTest extends TestCase
{
    public static function invalidSecrets(): iterable
    {
        yield [[]];
        yield [['']];
        yield [[123]];
        yield [['key' => 'secret']];
        yield [array_fill(0, 17, 'secret')];
    }

    #[DataProvider('invalidSecrets')]
    public function testInvalidSecretsFailFast(array $secrets): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Verifier($secrets);
    }

    public function testNegativeToleranceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Verifier(['secret'], -1);
    }

    public function testNegativeClockIsRejected(): void
    {
        $verifier = new Verifier(['secret'], clock: new class implements Clock {
            public function now(): int { return -1; }
        });
        $this->expectException(\LogicException::class);
        $verifier->verify('', 't=0,v1=' . hash_hmac('sha256', '0.', 'secret'));
    }

    public function testEmptyScopeIsRejected(): void
    {
        $store = new PdoSqliteEventStore(new PDO('sqlite::memory:'));
        $this->expectException(\InvalidArgumentException::class);
        new Guardian(new Verifier(['secret']), $store, '');
    }

    public function testSilentPdoModeIsRejected(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $this->expectException(\InvalidArgumentException::class);
        new PdoSqliteEventStore($pdo);
    }

    public function testInstallRefusesCallerTransaction(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $store = new PdoSqliteEventStore($pdo);
        $pdo->beginTransaction();
        try {
            $store->install();
            self::fail('Schema setup must not join business transactions');
        } catch (\LogicException) {
            self::assertTrue($pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    }
}
