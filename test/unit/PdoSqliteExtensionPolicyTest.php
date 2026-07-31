<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\pdo\PdoExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** PdoExtensionPolicy sqlite host / ENABLE gate (#24523). */
final class PdoSqliteExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostPdoSqlite(): void
    {
        if (\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('host pdo_sqlite loaded');
        }

        self::assertFalse(PdoExtensionPolicy::advertisesSqliteDriver());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('pdo_sqlite')
        );
    }

    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('host pdo_sqlite loaded');
        }
        if (!\PHPCompiler\ext\sqlite3\VmSqlite3Native::available()) {
            self::markTestSkipped('libsqlite3 FFI unavailable');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_PDO_SQLITE');
        putenv('PHP_COMPILER_ENABLE_PDO_SQLITE=1');
        try {
            self::assertTrue(PdoExtensionPolicy::advertisesSqliteDriver());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_PDO_SQLITE');
            } else {
                putenv('PHP_COMPILER_ENABLE_PDO_SQLITE='.$prevEnable);
            }
        }
    }
}
