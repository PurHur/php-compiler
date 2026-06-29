<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\RangeIntJitHelper;
use PHPUnit\Framework\TestCase;

/** range() int JIT routes through RangeIntJitHelper PHP not HashTableHelper LLVM (#13502). */
final class RangeIntRuntimeShrinkTest extends TestCase
{
    public function testRangeIntRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/RangeIntRuntime.php');
        $this->assertStringContainsString('RangeIntJitHelper', $runtime);
        $this->assertStringContainsString('HashTableHelper::buildIntegerRange', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/range.php');
        $this->assertStringContainsString('RangeIntRuntime::intRange', $builtin);
        $this->assertStringNotContainsString('HashTableHelper::buildIntegerRange', $builtin);
    }

    public function testRangeIntJitHelperMatchesVmIntRangeSemantics(): void
    {
        $asc = RangeIntJitHelper::intRangeCopy(1, 3, 1);
        $this->assertSame(3, $asc->getNumElements());
        $this->assertSame(1, $asc->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(2, $asc->findIndex(1)?->resolveIndirect()->toInt());
        $this->assertSame(3, $asc->findIndex(2)?->resolveIndirect()->toInt());

        $desc = RangeIntJitHelper::intRangeCopy(5, 1, -2);
        $this->assertSame(3, $desc->getNumElements());
        $this->assertSame(5, $desc->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(3, $desc->findIndex(1)?->resolveIndirect()->toInt());
        $this->assertSame(1, $desc->findIndex(2)?->resolveIndirect()->toInt());

        $stepped = RangeIntJitHelper::intRangeCopy(0, 6, 2);
        $this->assertSame(4, $stepped->getNumElements());
        $this->assertSame(0, $stepped->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(6, $stepped->findIndex(3)?->resolveIndirect()->toInt());
    }
}
