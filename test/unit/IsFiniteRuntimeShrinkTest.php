<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\IsFiniteJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * is_finite() NestedJIT helper inlines IEEE; userland JIT keeps JitIsFiniteKernel (#27590).
 */
final class IsFiniteRuntimeShrinkTest extends TestCase
{
    public function testIsFiniteUsesKernelLeafWithoutPhpcInternal(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/is_finite.php');
        $this->assertStringContainsString('MathIsFinite::invoke', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathIsFinite.php');
        $this->assertStringContainsString('JitIsFiniteKernel', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink', $bridge);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/IsFiniteJitHelper.php');
        $this->assertStringContainsString('$num === $num', $helper);
        $this->assertStringNotContainsString('phpc_is_finite_kernel', $helper);
        $this->assertStringNotContainsString('\is_finite', $helper);

        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/phpc_is_finite_kernel.php');
        $this->assertTrue(IsFiniteJitHelper::isFiniteArgv(1.0));
        $this->assertFalse(IsFiniteJitHelper::isFiniteArgv(\NAN));
        $this->assertFalse(IsFiniteJitHelper::isFiniteArgv(\INF));
    }

    public function testContextNoLongerAllowlistsIsFiniteKernelInternal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_is_finite_kernel', $source);
    }

    public function testSpineOmitsPhpcIsFiniteKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitIsFiniteKernel.php', $spine);
        $this->assertStringContainsString('IsFiniteJitHelper.php', $spine);
        $this->assertStringNotContainsString('phpc_is_finite_kernel.php', $spine);
    }
}
