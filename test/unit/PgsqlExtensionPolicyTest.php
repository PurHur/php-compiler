<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\pgsql\PgsqlExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** PgsqlExtensionPolicy host / ENABLE gate (#24994, #24627). */
final class PgsqlExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostPgsql(): void
    {
        if (\extension_loaded('pgsql')) {
            self::markTestSkipped('host ext/pgsql loaded');
        }

        self::assertFalse(PgsqlExtensionPolicy::advertisesExtension());
        self::assertFalse(PgsqlExtensionPolicy::advertisesBuiltins());
    }

    public function testProfile84AloneDoesNotInventPgsql(): void
    {
        if (\extension_loaded('pgsql')) {
            self::markTestSkipped('host ext/pgsql loaded');
        }

        $prevProfile = getenv('PHP_COMPILER_PROFILE');
        $prevEnable = getenv('PHP_COMPILER_ENABLE_PGSQL');
        putenv('PHP_COMPILER_PROFILE=8.4');
        putenv('PHP_COMPILER_ENABLE_PGSQL');
        try {
            self::assertFalse(PgsqlExtensionPolicy::advertisesExtension());
            self::assertFalse(PgsqlExtensionPolicy::advertisesPhp83ErrorContextVisibility());
            self::assertFalse(PgsqlExtensionPolicy::advertisesPhp84Helpers());
        } finally {
            if (false === $prevProfile) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prevProfile);
            }
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_PGSQL');
            } else {
                putenv('PHP_COMPILER_ENABLE_PGSQL='.$prevEnable);
            }
        }
    }

    public function testExplicitEnableAdvertisesWhenLibpqAvailable(): void
    {
        if (\extension_loaded('pgsql')) {
            self::markTestSkipped('host ext/pgsql loaded');
        }

        $prevEnable = getenv('PHP_COMPILER_ENABLE_PGSQL');
        putenv('PHP_COMPILER_ENABLE_PGSQL=1');
        try {
            $available = \PHPCompiler\ext\pgsql\VmPgsqlNative::available();
            self::assertSame($available, PgsqlExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prevEnable) {
                putenv('PHP_COMPILER_ENABLE_PGSQL');
            } else {
                putenv('PHP_COMPILER_ENABLE_PGSQL='.$prevEnable);
            }
        }
    }
}
