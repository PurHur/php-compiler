<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Spaceship scalar JIT routes through CompareJitHelper PHP, not hand-written LLVM (#9381). */
final class SpaceshipCompareJitHelperTest extends TestCase
{
    public function testCompareJitHelperDelegatesScalarSemantics(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/VM/CompareJitHelper.php');
        $this->assertStringContainsString('spaceshipNumeric', $source);
        $this->assertStringContainsString('spaceshipNumberString', $source);
    }

    public function testSpaceshipRuntimeCompilesCompareJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SpaceshipRuntime.php');
        $this->assertStringContainsString('CompareJitHelper', $source);
        $this->assertStringContainsString('ensureCompareJitHelperCompiled', $source);
    }

    public function testSpaceshipCompareJitRoutesScalarsThroughHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SpaceshipCompareJit.php');
        $this->assertStringContainsString('CompareJitHelper::longSpaceship', $source);
        $this->assertStringContainsString('CompareJitHelper::stringSpaceship', $source);
        $this->assertStringNotContainsString('JitFloatCompare', $source);
        $this->assertStringNotContainsString('stringIsNumeric', $source);
    }

    public function testCompareJitHelperScalarSemantics(): void
    {
        $this->assertSame(-1, \PHPCompiler\VM\CompareJitHelper::longSpaceship(1, 2));
        $this->assertSame(-1, \PHPCompiler\VM\CompareJitHelper::stringSpaceship('a', 'b'));
        $this->assertSame(-1, \PHPCompiler\VM\CompareJitHelper::spaceshipNumberString(1.0, 'b', 1));
        $this->assertSame(0, \PHPCompiler\VM\CompareJitHelper::spaceshipNumberString(1.0, '1', 1));
    }
}
