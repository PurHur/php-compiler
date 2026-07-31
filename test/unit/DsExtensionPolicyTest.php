<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\ds\DsExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** DsExtensionPolicy host / ENABLE gate (#25086). */
final class DsExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostDs(): void
    {
        if (\extension_loaded('ds')) {
            self::markTestSkipped('host ext/ds loaded');
        }

        self::assertFalse(DsExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('ds')
        );
        self::assertFalse(
            isset($runtime->vmContext->classes['ds\\vector'])
        );
    }

    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('ds')) {
            self::markTestSkipped('host ext/ds loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_DS');
        putenv('PHP_COMPILER_ENABLE_DS=1');
        try {
            self::assertTrue(DsExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_DS');
            } else {
                putenv('PHP_COMPILER_ENABLE_DS='.$prevEnable);
            }
        }
    }
}
