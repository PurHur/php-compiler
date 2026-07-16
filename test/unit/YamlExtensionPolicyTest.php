<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\yaml\YamlExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** YamlExtensionPolicy phantom withhold on reference profile (#6275). */
final class YamlExtensionPolicyTest extends TestCase
{
    public function testAdvertisesExtensionFalseOnReferenceProfile(): void
    {
        self::assertFalse(CompilerVersion::supportsYaml());
        self::assertFalse(YamlExtensionPolicy::advertisesExtension());
    }

    public function testAdvertisesExtensionTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsYaml());
            self::assertTrue(YamlExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
