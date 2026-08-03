<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** is_finite() uses MathIsFinite → JitIsFiniteKernel (#15188, #27021). */
final class IsFiniteRuntimeShrinkTest extends TestCase
{
    public function testIsFiniteUsesKernelLeaf(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/is_finite.php');
        $this->assertStringContainsString('MathIsFinite::invoke', $builtin);
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathIsFinite.php');
        $this->assertStringContainsString('JitIsFiniteKernel', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink', $bridge);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/IsFiniteJitHelper.php');
        $this->assertStringContainsString('phpc_is_finite_kernel', $helper);
        $this->assertStringNotContainsString('\is_finite', $helper);
    }

    public function testContextAllowlistsKernels(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_is_finite_kernel', $source);
    }

    public function testSpineIncludesKernels(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitIsFiniteKernel.php', $spine);
        $this->assertStringContainsString('phpc_is_finite_kernel.php', $spine);
    }
}
