<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\gnupg\GnupgExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** GnupgExtensionPolicy host / ENABLE gate (#25360). */
final class GnupgExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostGnupg(): void
    {
        if (\extension_loaded('gnupg')) {
            self::markTestSkipped('host ext/gnupg loaded');
        }

        self::assertFalse(GnupgExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('gnupg')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'gnupg_init')
        );
        self::assertFalse(isset($runtime->vmContext->classes['gnupg']));
    }

    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('gnupg')) {
            self::markTestSkipped('host ext/gnupg loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_GNUPG');
        putenv('PHP_COMPILER_ENABLE_GNUPG=1');
        try {
            self::assertTrue(GnupgExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_GNUPG');
            } else {
                putenv('PHP_COMPILER_ENABLE_GNUPG='.$prevEnable);
            }
        }
    }
}
