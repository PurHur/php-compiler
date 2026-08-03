<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

final class IsNanRuntimeShrinkTest extends TestCase
{
    public function testIsNanUsesKernelLeaf(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathIsNan.php');
        $this->assertStringContainsString('JitIsNanKernel', $bridge);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/IsNanJitHelper.php');
        $this->assertStringContainsString('phpc_is_nan_kernel', $helper);
        $this->assertStringNotContainsString('\is_nan', $helper);
    }

    public function testFloatCompareDeclaresIsNanOnDemand(): void
    {
        $compare = (string) file_get_contents(__DIR__.'/../../lib/VM/VmFloatCompare.php');
        $this->assertStringContainsString('function lookupOrDeclareIsNan', $compare);
    }
}
