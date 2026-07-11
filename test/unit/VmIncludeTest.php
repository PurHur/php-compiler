<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\VmInclude;
use PHPUnit\Framework\TestCase;

/** VmInclude SSOT for self-host spine include guards (#10063). */
final class VmIncludeTest extends TestCase
{
    public function testPathMatchesSelfHostSpineSkipSuffix(): void
    {
        self::assertTrue(VmInclude::pathMatchesSelfHostSpineSkipSuffix('vendor/autoload.php'));
        self::assertTrue(VmInclude::pathMatchesSelfHostSpineSkipSuffix('/repo/vendor/autoload.php'));
        self::assertFalse(VmInclude::pathMatchesSelfHostSpineSkipSuffix('lib/VM.php'));
    }

    public function testShouldSkipSelfHostSpineCliIncludeWhenSelfHostAot(): void
    {
        $prev = getenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        try {
            self::assertTrue(VmInclude::shouldSkipSelfHostSpineCliInclude('vendor/autoload.php'));
            self::assertFalse(VmInclude::shouldSkipSelfHostSpineCliInclude('lib/Compiler.php'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_SELFHOST_AOT');
            } else {
                putenv('PHP_COMPILER_SELFHOST_AOT='.$prev);
            }
        }
    }

    public function testShouldStubM3SidecarHostNonLiteralIncludeForLibSpineBundle(): void
    {
        $prev = getenv('PHP_COMPILER_LIB_SPINE_BUNDLE');
        putenv('PHP_COMPILER_LIB_SPINE_BUNDLE=1');
        try {
            self::assertTrue(
                VmInclude::shouldStubM3SidecarHostNonLiteralInclude('/compiler/lib/Compiler.php')
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_LIB_SPINE_BUNDLE');
            } else {
                putenv('PHP_COMPILER_LIB_SPINE_BUNDLE='.$prev);
            }
        }
    }
}
