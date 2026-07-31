<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\dba\DbaExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** DbaExtensionPolicy host / ENABLE gate (#24134). */
final class DbaExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostDba(): void
    {
        if (\extension_loaded('dba')) {
            self::markTestSkipped('host ext/dba loaded');
        }

        self::assertFalse(DbaExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('dba')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'dba_open')
        );
        self::assertFalse(isset($runtime->vmContext->classes['dba\\connection']));
    }

    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('dba')) {
            self::markTestSkipped('host ext/dba loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_DBA');
        putenv('PHP_COMPILER_ENABLE_DBA=1');
        try {
            self::assertTrue(DbaExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_DBA');
            } else {
                putenv('PHP_COMPILER_ENABLE_DBA='.$prevEnable);
            }
        }
    }
}
