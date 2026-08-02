<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * filter_var sanitizing-filter JIT routes through FilterSanitizeJitHelper PHP
 * via JitVmHelperLink::ensureCompiled (#11419 / #27033 / peer #26766).
 */
final class FilterSanitizeRuntimeShrinkTest extends TestCase
{
    public function testFilterSanitizeJitHelperDelegatesToVmFilter(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/FilterSanitizeJitHelper.php');
        $this->assertStringContainsString('VmFilter::sanitizeStringForJit', $source);
    }

    public function testStringFilterSanitizeRoutesThroughFilterSanitizeJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilterSanitize.php');
        $this->assertStringContainsString('FilterSanitizeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(150, \substr_count($source, "\n"), 'StringFilterSanitize must be a thin bridge');
    }

    public function testJitFilterRoutesThroughStringFilterSanitize(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('StringFilterSanitize::ensureLinked', $source);
    }

    public function testFilterSanitizeJitHelperSemanticsMatchVmFilter(): void
    {
        // FILTER_SANITIZE_SPECIAL_CHARS = 515 — numeric entities (php-src sanitizing_filters.c)
        $this->assertSame(
            '&#60;b&#62;',
            \PHPCompiler\ext\filter\FilterSanitizeJitHelper::sanitize(515, '<b>', 0)
        );
        // FILTER_SANITIZE_FULL_SPECIAL_CHARS = 522
        $this->assertSame(
            '&quot;x&quot;',
            \PHPCompiler\ext\filter\FilterSanitizeJitHelper::sanitize(522, '"x"', 0)
        );
    }

    public function testSpineBundleIncludesFilterSanitizeJitHelper(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FilterSanitizeJitHelper.php', $spine);
        $this->assertStringContainsString('StringFilterSanitize.php', $spine);
    }
}
