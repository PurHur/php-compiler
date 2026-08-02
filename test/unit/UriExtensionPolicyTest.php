<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\uri\UriExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** UriExtensionPolicy phantom withhold — reference + PROFILE≤8.4; advertise on 8.5+ (#17830, #26254). */
final class UriExtensionPolicyTest extends TestCase
{
    public function testAdvertisesExtensionFalseOnReferenceProfile(): void
    {
        self::assertFalse(CompilerVersion::supportsUri());
        self::assertFalse(UriExtensionPolicy::advertisesExtension());
    }

    public function testAdvertisesExtensionFalseOnProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertFalse(CompilerVersion::supportsUri());
            self::assertFalse(UriExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAdvertisesExtensionTrueOnProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            self::assertTrue(CompilerVersion::supportsUri());
            self::assertTrue(UriExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
