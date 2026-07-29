<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * filter_var FILTER_VALIDATE_IP JIT routes through FilterIpJitHelper PHP
 * via JitVmHelperLink::ensureCompiled (#4403 / #24650 / peer #23612).
 */
final class FilterIpRuntimeShrinkTest extends TestCase
{
    public function testFilterIpJitHelperDelegatesToVmFilter(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/FilterIpJitHelper.php');
        $this->assertStringContainsString('VmFilter::isValidIpAddress', $source);
    }

    public function testStringFilterIpRoutesThroughFilterIpJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilterIp.php');
        $this->assertStringContainsString('FilterIpJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(150, \substr_count($source, "\n"), 'StringFilterIp must be a thin bridge');
    }

    public function testJitFilterRoutesThroughStringFilterIp(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('StringFilterIp::ensureLinked', $source);
    }

    public function testFilterIpJitHelperSemanticsMatchVmFilter(): void
    {
        $this->assertSame('127.0.0.1', \PHPCompiler\ext\filter\FilterIpJitHelper::validate('127.0.0.1'));
        $this->assertNull(\PHPCompiler\ext\filter\FilterIpJitHelper::validate('not-an-ip'));
        $this->assertSame('::1', \PHPCompiler\ext\filter\FilterIpJitHelper::validate('::1'));
    }

    public function testSpineBundleIncludesFilterIpJitHelper(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FilterIpJitHelper.php', $spine);
        $this->assertStringContainsString('StringFilterIp.php', $spine);
    }
}
