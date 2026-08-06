<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\RangeIntJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * range() int: VM SSOT in RangeIntJitHelper; AOT/JIT bridge builds __hashtable__ in LLVM
 * (NestedJIT array returns hang/segfault under thin AOT — #26956 / peer #26910).
 */
final class RangeIntRuntimeShrinkTest extends TestCase
{
    public function testRangeIntRuntimeBuildsHtViaSetLongAtNotNestedJitHelper(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/RangeIntRuntime.php');
        $this->assertStringContainsString('__hashtable__setLongAt', $runtime);
        $this->assertStringContainsString('HashTableHelper::alloc', $runtime);
        $this->assertStringContainsString('__hashtable__setStringAt', $runtime);
        $this->assertStringContainsString('__hashtable__setDoubleAt', $runtime);
        $this->assertStringContainsString('__range_char__copy', $runtime);
        $this->assertStringContainsString('__range_float__copy', $runtime);
        $this->assertStringContainsString('charRange', $runtime);
        $this->assertStringContainsString('floatRange', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('HashTableHelper::buildIntegerRange', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/RangeIntJitHelper.php');
        $this->assertStringContainsString('buildIntRange', $helper);
        $this->assertStringNotContainsString('VmRange::', $helper);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/range.php');
        $this->assertStringContainsString('RangeIntRuntime::intRange', $builtin);
        $this->assertStringContainsString('RangeIntRuntime::charRange', $builtin);
        $this->assertStringContainsString('RangeIntRuntime::floatRange', $builtin);
        $this->assertStringContainsString('charLetterLiteral', $builtin);
        $this->assertStringContainsString('callFloatRange', $builtin);
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

        // php-src: |step| > span → ValueError; |step| == span OK (#26657).
        $equalSpan = RangeIntJitHelper::intRangeCopy(0, 2, 2);
        $this->assertSame(2, $equalSpan->getNumElements());
        try {
            RangeIntJitHelper::intRangeCopy(0, 1, 2);
            $this->fail('expected ValueError for oversized step');
        } catch (\ValueError $e) {
            $this->assertSame(
                'range(): Argument #3 ($step) must not exceed the specified range',
                $e->getMessage()
            );
        }
    }

    public function testDeadBuildIntegerRangeLlvmDeletedFromHashTableWrite(): void
    {
        $write = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableWriteLlvm.php');
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringNotContainsString('buildIntegerRange', $write);
        $this->assertStringNotContainsString('buildIntegerRange', $helper);
    }
}
