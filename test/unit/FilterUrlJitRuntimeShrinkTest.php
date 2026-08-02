<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * filter_var FILTER_VALIDATE_URL JIT routes through FilterUrlJitHelper PHP
 * via JitVmHelperLink::ensureCompiled (#11274 / #26766 / peer #26699).
 */
final class FilterUrlJitRuntimeShrinkTest extends TestCase
{
    public function testFilterUrlJitHelperDelegatesToVmFilter(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/FilterUrlJitHelper.php');
        $this->assertStringContainsString('VmFilter::isValidUrlSubset', $source);
    }

    public function testStringFilterUrlRoutesThroughFilterUrlJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilterUrl.php');
        $this->assertStringContainsString('FilterUrlJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(150, \substr_count($source, "\n"), 'StringFilterUrl must be a thin bridge');
    }

    public function testJitFilterRoutesThroughStringFilterUrl(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('StringFilterUrl::ensureLinked', $source);
    }

    public function testFilterUrlJitHelperSemanticsMatchVmFilter(): void
    {
        $this->assertSame('https://example.com', \PHPCompiler\ext\filter\FilterUrlJitHelper::validate('https://example.com'));
        $this->assertSame(
            'http://127.0.0.1:8080/path?q=1#frag',
            \PHPCompiler\ext\filter\FilterUrlJitHelper::validate('http://127.0.0.1:8080/path?q=1#frag')
        );
        $this->assertSame('ftp://example.com', \PHPCompiler\ext\filter\FilterUrlJitHelper::validate('ftp://example.com'));
        $this->assertNull(\PHPCompiler\ext\filter\FilterUrlJitHelper::validate('not a url'));
        $this->assertNull(\PHPCompiler\ext\filter\FilterUrlJitHelper::validate('http://'));
    }

    public function testSpineBundleIncludesFilterUrlJitHelper(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FilterUrlJitHelper.php', $spine);
        $this->assertStringContainsString('StringFilterUrl.php', $spine);
    }
}
