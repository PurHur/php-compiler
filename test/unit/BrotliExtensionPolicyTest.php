<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\brotli\BrotliExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** BrotliExtensionPolicy phantom withhold on reference profile (#17563). */
final class BrotliExtensionPolicyTest extends TestCase
{
    public function testAdvertisesExtensionFalseOnReferenceProfile(): void
    {
        self::assertFalse(CompilerVersion::supportsBrotli());
        self::assertFalse(BrotliExtensionPolicy::advertisesExtension());
    }

    public function testAdvertisesExtensionTrueOnForwardProfileWhenCoreAvailable(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsBrotli());
            self::assertTrue(BrotliExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
