<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

final class IsInfiniteRuntimeShrinkTest extends TestCase
{
    public function testIsInfiniteUsesKernelLeaf(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathIsInfinite.php');
        $this->assertStringContainsString('JitIsInfiniteKernel', $bridge);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/IsInfiniteJitHelper.php');
        $this->assertStringContainsString('phpc_is_infinite_kernel', $helper);
        $this->assertStringNotContainsString('\is_infinite', $helper);
    }
}
