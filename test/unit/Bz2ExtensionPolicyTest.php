<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\bz2\Bz2ExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** Bz2ExtensionPolicy host / ENABLE gate (#14219, #25011). */
final class Bz2ExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostBz2(): void
    {
        if (\extension_loaded('bz2')) {
            self::markTestSkipped('host ext/bz2 loaded');
        }

        self::assertFalse(Bz2ExtensionPolicy::advertisesExtension());
    }

    public function testProfile84AloneDoesNotInventBz2(): void
    {
        if (\extension_loaded('bz2')) {
            self::markTestSkipped('host ext/bz2 loaded');
        }

        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        $prevEnable = getenv('PHP_COMPILER_ENABLE_BZ2');
        putenv('PHP_COMPILER_PROFILE=8.4');
        putenv('PHP_COMPILER_ENABLE_BZ2');
        try {
            self::assertFalse(Bz2ExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevProfile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
            }
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_BZ2');
            } else {
                putenv('PHP_COMPILER_ENABLE_BZ2='.$prevEnable);
            }
        }
    }

    public function testExplicitEnableAdvertisesWhenNativeAvailable(): void
    {
        if (\extension_loaded('bz2')) {
            self::markTestSkipped('host ext/bz2 loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_BZ2');
        putenv('PHP_COMPILER_ENABLE_BZ2=1');
        try {
            $available = \PHPCompiler\ext\bz2\VmBz2Native::available();
            self::assertSame($available, Bz2ExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_BZ2');
            } else {
                putenv('PHP_COMPILER_ENABLE_BZ2='.$prevEnable);
            }
        }
    }
}
