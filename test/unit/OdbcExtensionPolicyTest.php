<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\odbc\OdbcExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** OdbcExtensionPolicy host / ENABLE gate (#23969). */
final class OdbcExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostOdbc(): void
    {
        if (\extension_loaded('odbc')) {
            self::markTestSkipped('host ext/odbc loaded');
        }

        self::assertFalse(OdbcExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('odbc')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'odbc_connect')
        );
    }

    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('odbc')) {
            self::markTestSkipped('host ext/odbc loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_ODBC');
        putenv('PHP_COMPILER_ENABLE_ODBC=1');
        try {
            self::assertTrue(OdbcExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_ODBC');
            } else {
                putenv('PHP_COMPILER_ENABLE_ODBC='.$prevEnable);
            }
        }
    }
}
