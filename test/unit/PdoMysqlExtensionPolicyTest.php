<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\pdo\PdoExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** PdoExtensionPolicy mysql host / ENABLE gate (#27332). */
final class PdoMysqlExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostPdoMysql(): void
    {
        if (\extension_loaded('pdo_mysql')) {
            self::markTestSkipped('host pdo_mysql loaded');
        }

        self::assertFalse(PdoExtensionPolicy::advertisesMysqlDriver());
        self::assertFalse(PdoExtensionPolicy::advertisesMysqlSubclass());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('pdo_mysql')
        );
    }

    public function testProfile84AloneDoesNotAdvertiseSubclass(): void
    {
        if (\extension_loaded('pdo_mysql')) {
            self::markTestSkipped('host pdo_mysql loaded');
        }

        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        $prevEnable = getenv('PHP_COMPILER_ENABLE_PDO_MYSQL');
        putenv('PHP_COMPILER_PROFILE=8.4');
        putenv('PHP_COMPILER_ENABLE_PDO_MYSQL');
        try {
            self::assertFalse(PdoExtensionPolicy::advertisesMysqlDriver());
            self::assertFalse(PdoExtensionPolicy::advertisesMysqlSubclass());
            self::assertTrue(PdoExtensionPolicy::advertisesDriverSpecificSubclasses());
        } finally {
            if (false === $prevProfile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
            }
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_PDO_MYSQL');
            } else {
                putenv('PHP_COMPILER_ENABLE_PDO_MYSQL='.$prevEnable);
            }
        }
    }

    public function testExplicitEnableAdvertisesSubclassOnProfile84(): void
    {
        if (\extension_loaded('pdo_mysql')) {
            self::markTestSkipped('host pdo_mysql loaded');
        }

        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        $prevEnable = getenv('PHP_COMPILER_ENABLE_PDO_MYSQL');
        putenv('PHP_COMPILER_PROFILE=8.4');
        putenv('PHP_COMPILER_ENABLE_PDO_MYSQL=1');
        try {
            self::assertTrue(PdoExtensionPolicy::advertisesMysqlDriver());
            self::assertTrue(PdoExtensionPolicy::advertisesMysqlSubclass());
        } finally {
            if (false === $prevProfile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
            }
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_PDO_MYSQL');
            } else {
                putenv('PHP_COMPILER_ENABLE_PDO_MYSQL='.$prevEnable);
            }
        }
    }
}
