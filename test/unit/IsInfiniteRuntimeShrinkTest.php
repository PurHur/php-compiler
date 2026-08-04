<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\IsInfiniteJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * is_infinite() NestedJIT helper inlines ±INF; userland JIT keeps JitIsInfiniteKernel (#27590).
 */
final class IsInfiniteRuntimeShrinkTest extends TestCase
{
    public function testIsInfiniteUsesKernelLeafWithoutPhpcInternal(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathIsInfinite.php');
        $this->assertStringContainsString('JitIsInfiniteKernel', $bridge);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/IsInfiniteJitHelper.php');
        $this->assertStringContainsString('\\INF', $helper);
        $this->assertStringNotContainsString('phpc_is_infinite_kernel', $helper);
        $this->assertStringNotContainsString('\is_infinite', $helper);

        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/phpc_is_infinite_kernel.php');
        $this->assertTrue(IsInfiniteJitHelper::isInfiniteArgv(\INF));
        $this->assertTrue(IsInfiniteJitHelper::isInfiniteArgv(-\INF));
        $this->assertFalse(IsInfiniteJitHelper::isInfiniteArgv(1.0));
        $this->assertFalse(IsInfiniteJitHelper::isInfiniteArgv(\NAN));
    }

    public function testContextNoLongerAllowlistsIsInfiniteKernelInternal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_is_infinite_kernel', $source);
    }

    public function testSpineOmitsPhpcIsInfiniteKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitIsInfiniteKernel.php', $spine);
        $this->assertStringContainsString('IsInfiniteJitHelper.php', $spine);
        $this->assertStringNotContainsString('phpc_is_infinite_kernel.php', $spine);
    }
}
