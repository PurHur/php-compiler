<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\zip\ZipExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** ZipExtensionPolicy host / ENABLE gate (#25010). */
final class ZipExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostZip(): void
    {
        if (\extension_loaded('zip')) {
            self::markTestSkipped('host ext/zip loaded');
        }

        self::assertFalse(ZipExtensionPolicy::advertisesExtension());
    }

    public function testProfile84AloneDoesNotInventZip(): void
    {
        if (\extension_loaded('zip')) {
            self::markTestSkipped('host ext/zip loaded');
        }

        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        $prevEnable = getenv('PHP_COMPILER_ENABLE_ZIP');
        putenv('PHP_COMPILER_PROFILE=8.4');
        putenv('PHP_COMPILER_ENABLE_ZIP');
        try {
            self::assertFalse(ZipExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevProfile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
            }
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_ZIP');
            } else {
                putenv('PHP_COMPILER_ENABLE_ZIP='.$prevEnable);
            }
        }
    }

    public function testExplicitEnableAdvertisesZip(): void
    {
        if (\extension_loaded('zip')) {
            self::markTestSkipped('host ext/zip loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_ZIP');
        putenv('PHP_COMPILER_ENABLE_ZIP=1');
        try {
            self::assertTrue(ZipExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_ZIP');
            } else {
                putenv('PHP_COMPILER_ENABLE_ZIP='.$prevEnable);
            }
        }
    }
}
