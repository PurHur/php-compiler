<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * filter_var FILTER_VALIDATE_MAC JIT routes through FilterMacJitHelper PHP
 * via JitVmHelperLink::ensureCompiled (#17411 / #25019 / peer #24650).
 */
final class FilterMacRuntimeShrinkTest extends TestCase
{
    public function testFilterMacJitHelperDelegatesToVmFilter(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/FilterMacJitHelper.php');
        $this->assertStringContainsString('VmFilter::isValidMacAddress', $source);
    }

    public function testStringFilterMacRoutesThroughFilterMacJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilterMac.php');
        $this->assertStringContainsString('FilterMacJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(150, \substr_count($source, "\n"), 'StringFilterMac must be a thin bridge');
    }

    public function testJitFilterRoutesThroughStringFilterMac(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('StringFilterMac::ensureLinked', $source);
    }

    public function testFilterMacJitHelperSemanticsMatchVmFilter(): void
    {
        $this->assertSame('00:11:22:33:44:55', \PHPCompiler\ext\filter\FilterMacJitHelper::validate('00:11:22:33:44:55'));
        $this->assertNull(\PHPCompiler\ext\filter\FilterMacJitHelper::validate('not-a-mac'));
        $this->assertSame('00-11-22-33-44-55', \PHPCompiler\ext\filter\FilterMacJitHelper::validate('00-11-22-33-44-55'));
    }

    public function testSpineBundleIncludesFilterMacJitHelper(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FilterMacJitHelper.php', $spine);
        $this->assertStringContainsString('StringFilterMac.php', $spine);
    }
}
