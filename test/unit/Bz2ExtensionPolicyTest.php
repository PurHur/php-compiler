<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\bz2\Bz2ExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** Bz2ExtensionPolicy phantom withhold on reference profile (#14219, #16853). */
final class Bz2ExtensionPolicyTest extends TestCase
{
    public function testAdvertisesExtensionFalseOnReferenceProfile(): void
    {
        self::assertFalse(CompilerVersion::supportsBz2());
        self::assertFalse(Bz2ExtensionPolicy::advertisesExtension());
    }

    public function testAdvertisesExtensionTrueOnForwardProfileWhenCoreAvailable(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsBz2());
            self::assertTrue(Bz2ExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
