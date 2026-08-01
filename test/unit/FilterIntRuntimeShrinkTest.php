<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * filter_var FILTER_VALIDATE_INT JIT routes through FilterIntJitHelper PHP
 * via JitVmHelperLink::ensureCompiled (#11757 / #26699 / peer #25019).
 */
final class FilterIntRuntimeShrinkTest extends TestCase
{
    public function testFilterIntJitHelperDelegatesToVmFilter(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/FilterIntJitHelper.php');
        $this->assertStringContainsString('VmFilter::parseIntFilterString', $source);
    }

    public function testStringFilterIntRoutesThroughFilterIntJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilterInt.php');
        $this->assertStringContainsString('FilterIntJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(150, \substr_count($source, "\n"), 'StringFilterInt must be a thin bridge');
    }

    public function testJitFilterRoutesThroughStringFilterInt(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('StringFilterInt::ensureLinked', $source);
    }

    public function testFilterIntJitHelperSemanticsMatchVmFilter(): void
    {
        $this->assertSame(42, \PHPCompiler\ext\filter\FilterIntJitHelper::validateString('42', 0));
        $this->assertSame(-1, \PHPCompiler\ext\filter\FilterIntJitHelper::validateString('not-an-int', 0));
        $this->assertSame(0, \PHPCompiler\ext\filter\FilterIntJitHelper::validateString('0', 0));
    }

    public function testSpineBundleIncludesFilterIntJitHelper(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FilterIntJitHelper.php', $spine);
        $this->assertStringContainsString('StringFilterInt.php', $spine);
    }
}
