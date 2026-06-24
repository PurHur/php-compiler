<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** filter_var FILTER_VALIDATE_URL JIT routes through FilterUrlJitHelper PHP (#11274). */
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
        $this->assertLessThan(160, \substr_count($source, "\n"), 'StringFilterUrl must be a thin bridge');
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
}
