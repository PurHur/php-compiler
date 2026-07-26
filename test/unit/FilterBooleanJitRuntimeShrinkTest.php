<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * filter_var FILTER_VALIDATE_BOOLEAN JIT routes through FilterBooleanJitHelper PHP
 * via JitVmHelperLink::ensureCompiled (#9858 / #23612 / peer #23556).
 */
final class FilterBooleanJitRuntimeShrinkTest extends TestCase
{
    public function testFilterBooleanJitHelperDelegatesToVmFilter(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/FilterBooleanJitHelper.php');
        $this->assertStringContainsString('VmFilter::parseBooleanString', $source);
    }

    public function testStringFilterBooleanRoutesThroughFilterBooleanJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilterBoolean.php');
        $this->assertStringContainsString('FilterBooleanJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('emitLengthCascade', $source);
        $this->assertStringNotContainsString('bytesMatchLiteral', $source);
        $this->assertStringNotContainsString('matchWords', $source);
        $this->assertLessThan(150, \substr_count($source, "\n"), 'StringFilterBoolean must be a thin bridge');
    }

    public function testJitFilterRoutesThroughStringFilterBoolean(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('StringFilterBoolean::ensureLinked', $source);
    }

    public function testFilterBooleanJitHelperSemanticsMatchVmFilter(): void
    {
        $this->assertSame(1, \PHPCompiler\ext\filter\FilterBooleanJitHelper::parseString('true'));
        $this->assertSame(0, \PHPCompiler\ext\filter\FilterBooleanJitHelper::parseString('false'));
        $this->assertSame(1, \PHPCompiler\ext\filter\FilterBooleanJitHelper::parseString('ON'));
        $this->assertSame(0, \PHPCompiler\ext\filter\FilterBooleanJitHelper::parseString('no'));
        $this->assertSame(1, \PHPCompiler\ext\filter\FilterBooleanJitHelper::parseString('1'));
        $this->assertSame(0, \PHPCompiler\ext\filter\FilterBooleanJitHelper::parseString('0'));
        $this->assertSame(-1, \PHPCompiler\ext\filter\FilterBooleanJitHelper::parseString('maybe'));
        $this->assertSame(1, \PHPCompiler\ext\filter\FilterBooleanJitHelper::parseString('  yes  '));
    }
}
