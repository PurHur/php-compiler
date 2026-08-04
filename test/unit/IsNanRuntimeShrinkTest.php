<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\IsNanJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * is_nan() NestedJIT helper inlines IEEE; userland JIT keeps JitIsNanKernel (#27590).
 */
final class IsNanRuntimeShrinkTest extends TestCase
{
    public function testIsNanUsesKernelLeafWithoutPhpcInternal(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathIsNan.php');
        $this->assertStringContainsString('JitIsNanKernel', $bridge);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/IsNanJitHelper.php');
        $this->assertStringContainsString('$num !== $num', $helper);
        $this->assertStringNotContainsString('phpc_is_nan_kernel', $helper);
        $this->assertStringNotContainsString('\is_nan', $helper);

        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/phpc_is_nan_kernel.php');
        $this->assertTrue(IsNanJitHelper::isNanArgv(\NAN));
        $this->assertFalse(IsNanJitHelper::isNanArgv(1.0));
    }

    public function testVmFloatCompareUsesMathIsNanNotLibc(): void
    {
        $compare = (string) file_get_contents(__DIR__.'/../../lib/VM/VmFloatCompare.php');
        $this->assertStringContainsString('MathIsNan::invoke', $compare);
        $this->assertStringNotContainsString('lookupOrDeclareIsNan', $compare);
        $this->assertStringNotContainsString("'isnan'", $compare);

        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'isnan'", $libc);
        $this->assertStringNotContainsString("'isinf'", $libc);
    }

    public function testContextNoLongerAllowlistsIsNanKernelInternal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_is_nan_kernel', $source);
    }

    public function testSpineOmitsPhpcIsNanKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitIsNanKernel.php', $spine);
        $this->assertStringContainsString('IsNanJitHelper.php', $spine);
        $this->assertStringNotContainsString('phpc_is_nan_kernel.php', $spine);
    }
}
