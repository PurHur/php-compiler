<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Spaceship (<=>) LLVM quarantined in ext/standard (#9381, #19623).
 */
final class SpaceshipCompareKernelShrinkTest extends TestCase
{
    public function testBuiltinSpaceshipCompareJitMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/SpaceshipCompareJit.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitSpaceshipCompareKernel.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SpaceshipRuntime.php');
        $this->assertStringContainsString('JitSpaceshipCompareKernel', $runtime);
        $this->assertStringNotContainsString('SpaceshipCompareJit', $runtime);
    }

    public function testKernelPresent(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSpaceshipCompareKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitSpaceshipCompareKernel', $source);
        $this->assertStringContainsString('__value__spaceship', $source);
        $this->assertStringContainsString('__object__compareSpaceship', $source);
        $this->assertStringContainsString('__hashtable__compareSpaceship', $source);
    }

    public function testSpineBundleIncludesKernelNotBuiltinJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitSpaceshipCompareKernel.php', $spine);
        $this->assertStringNotContainsString('SpaceshipCompareJit.php', $spine);
        $this->assertStringContainsString('SpaceshipRuntime.php', $spine);
    }
}
