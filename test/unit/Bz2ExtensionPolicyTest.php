<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\bz2\Bz2ExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** Bz2ExtensionPolicy phantom withhold on reference profile (#14219). */
final class Bz2ExtensionPolicyTest extends TestCase
{
    public function testAdvertisesExtensionFalseOnReferenceProfile(): void
    {
        self::assertFalse(CompilerVersion::supportsBz2());
        self::assertFalse(Bz2ExtensionPolicy::advertisesExtension());
    }
}
