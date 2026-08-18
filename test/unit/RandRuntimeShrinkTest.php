<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\RandJitHelper;
use PHPCompiler\ext\standard\VmMt19937;
use PHPUnit\Framework\TestCase;

/**
 * Rand NestedJIT via JitVmHelperLink::ensureCompiled (#25252 / peer #22495).
 */
final class RandRuntimeShrinkTest extends TestCase
{
    public function testRandRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Rand.php');
        $this->assertStringContainsString('RandJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testRandJitHelperDelegatesToVmMt19937(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/RandJitHelper.php');
        $this->assertStringContainsString('VmMt19937::mtRand31', $source);
        $this->assertStringContainsString('VmMt19937::randRange', $source);
        $this->assertStringContainsString('VmMt19937::range', $source);
        $this->assertStringContainsString('VmMt19937::seed', $source);
        $this->assertStringContainsString('function seedWithMode', $source);
    }

    public function testRandJitHelperMatchesVmMt19937(): void
    {
        VmMt19937::seed(12345);
        $expected31 = VmMt19937::mtRand31();
        $expectedRange = VmMt19937::randRange(1, 100);
        $expectedMtRange = VmMt19937::range(5, 50);

        VmMt19937::seed(12345);
        $this->assertSame($expected31, RandJitHelper::mtRand31());
        $this->assertSame($expectedRange, RandJitHelper::randRange(1, 100));
        $this->assertSame($expectedMtRange, RandJitHelper::mtRandRange(5, 50));
    }

    public function testSpineBundleIncludesRandJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('RandJitHelper.php', $spine);
        $this->assertStringContainsString('Builtin/Rand.php', $spine);
    }
}
