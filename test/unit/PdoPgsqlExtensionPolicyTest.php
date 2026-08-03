<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\pdo\PdoExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** PdoExtensionPolicy pgsql host / ENABLE gate (#26140). */
final class PdoPgsqlExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostPdoPgsql(): void
    {
        if (\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('host pdo_pgsql loaded');
        }

        self::assertFalse(PdoExtensionPolicy::advertisesPgsqlDriver());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('pdo_pgsql')
        );
    }

    public function testProfile84AloneDoesNotAdvertiseDriver(): void
    {
        if (\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('host pdo_pgsql loaded');
        }

        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertFalse(PdoExtensionPolicy::advertisesPgsqlDriver());
            self::assertTrue(PdoExtensionPolicy::advertisesPgsqlSubclass());
        } finally {
            if (false === $prevProfile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
            }
        }
    }

    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('host pdo_pgsql loaded');
        }
        if (!\PHPCompiler\ext\pgsql\VmPgsqlNative::available()) {
            self::markTestSkipped('libpq FFI unavailable');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_PDO_PGSQL');
        putenv('PHP_COMPILER_ENABLE_PDO_PGSQL=1');
        try {
            self::assertTrue(PdoExtensionPolicy::advertisesPgsqlDriver());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_PDO_PGSQL');
            } else {
                putenv('PHP_COMPILER_ENABLE_PDO_PGSQL='.$prevEnable);
            }
        }
    }
}
