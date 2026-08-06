<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\sqlite3\Sqlite3Constants;
use PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * SQLite3 class constants under declared casing (#28098 / #25929).
 */
final class Sqlite3AuthorizerConstantsTest extends TestCase
{
    private ?string $profilePrev = null;

    protected function setUp(): void
    {
        parent::setUp();
        $prev = getenv('PHP_COMPILER_PROFILE');
        $this->profilePrev = false === $prev ? null : $prev;
        putenv('PHP_COMPILER_PROFILE=8.5');
        if (!Sqlite3ExtensionPolicy::advertisesExtension()) {
            self::markTestSkipped('sqlite3 withheld (#22791)');
        }
    }

    protected function tearDown(): void
    {
        if (null === $this->profilePrev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->profilePrev);
        }
        parent::tearDown();
    }

    public function test_sqlite3_authorizer_constants_use_declared_casing(): void
    {
        $runtime = new Runtime();
        $entry = $runtime->vmContext->classes['sqlite3'];
        self::assertArrayHasKey('OK', $entry->constants);
        self::assertArrayHasKey('DENY', $entry->constants);
        self::assertArrayHasKey('IGNORE', $entry->constants);
        self::assertArrayHasKey('CREATE_TABLE', $entry->constants);
        self::assertArrayNotHasKey('ok', $entry->constants);
        self::assertSame(Sqlite3Constants::OK, $entry->constants['OK']->toInt());
        self::assertSame(Sqlite3Constants::DENY, $entry->constants['DENY']->toInt());
        self::assertSame(Sqlite3Constants::IGNORE, $entry->constants['IGNORE']->toInt());
        self::assertSame(Sqlite3Constants::CREATE_TABLE, $entry->constants['CREATE_TABLE']->toInt());
        self::assertSame('OK', $entry->constNames['OK']);
        self::assertSame('DENY', $entry->constNames['DENY']);
        self::assertTrue(\PHPCompiler\ext\standard\VmConstants::constantDefined(
            $runtime->vmContext,
            'SQLite3::OK'
        ));
        $deny = \PHPCompiler\ext\standard\VmConstants::constantLookup($runtime->vmContext, 'SQLite3::DENY');
        self::assertNotNull($deny);
        self::assertSame(Sqlite3Constants::DENY, $deny->toInt());
    }
}
