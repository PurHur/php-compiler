<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\pspell\PspellExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** PspellExtensionPolicy host / ENABLE gate (#23968). */
final class PspellExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostPspell(): void
    {
        if (\extension_loaded('pspell')) {
            self::markTestSkipped('host ext/pspell loaded');
        }

        self::assertFalse(PspellExtensionPolicy::advertisesExtension());

        $runtime = new Runtime();
        self::assertFalse(
            ext\standard\ModuleRegistry::extensionLoaded('pspell')
        );
        self::assertFalse(
            ext\standard\VmReflection::functionExists($runtime->vmContext, 'pspell_new')
        );
    }

    public function testProfile84AloneDoesNotInventPspell(): void
    {
        if (\extension_loaded('pspell')) {
            self::markTestSkipped('host ext/pspell loaded');
        }

        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        $prevEnable = getenv('PHP_COMPILER_ENABLE_PSPELL');
        putenv('PHP_COMPILER_PROFILE=8.4');
        putenv('PHP_COMPILER_ENABLE_PSPELL');
        try {
            self::assertFalse(PspellExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevProfile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
            }
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_PSPELL');
            } else {
                putenv('PHP_COMPILER_ENABLE_PSPELL='.$prevEnable);
            }
        }
    }

    public function testExplicitEnableAdvertisesWhenNativeAvailable(): void
    {
        if (\extension_loaded('pspell')) {
            self::markTestSkipped('host ext/pspell loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_PSPELL');
        putenv('PHP_COMPILER_ENABLE_PSPELL=1');
        try {
            $available = \PHPCompiler\ext\pspell\VmPspellNative::available();
            self::assertSame($available, PspellExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_PSPELL');
            } else {
                putenv('PHP_COMPILER_ENABLE_PSPELL='.$prevEnable);
            }
        }
    }
}
