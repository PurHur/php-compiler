<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * filter_var FILTER_VALIDATE_URL JIT routes through FilterUrlValidate PHP
 * via JitVmHelperLink::ensureCompiled (#11274 / #26766 / #27206 / peer #27068).
 */
final class FilterUrlJitRuntimeShrinkTest extends TestCase
{
    public function testFilterUrlValidateIsSelfContained(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/FilterUrlValidate.php');
        $this->assertStringContainsString('function isValidInt', $source);
        $this->assertStringNotContainsString('preg_match(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\ext\\standard', $source);
    }

    public function testStringFilterUrlRoutesThroughFilterUrlValidate(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilterUrl.php');
        $this->assertStringContainsString('FilterUrlValidate::isValidInt', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(160, \substr_count($source, "\n"), 'StringFilterUrl must be a thin bridge');
    }

    public function testJitFilterRoutesThroughStringFilterUrl(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('StringFilterUrl::ensureLinked', $source);
        $this->assertStringContainsString('VmFilter::isValidUrlSubset', $source);
    }

    public function testFilterUrlValidateSemanticsMatchVmFilter(): void
    {
        $cases = [
            'https://example.com/a',
            'https://example.com',
            'http://127.0.0.1:8080/path?q=1#frag',
            'ftp://example.com',
            'mailto:a@b.co',
            'not a url',
            'http://',
            'https://exam_ple.com',
            'news:comp.lang.php',
        ];
        foreach ($cases as $c) {
            $vm = \PHPCompiler\ext\filter\VmFilter::isValidUrlSubset($c);
            $aot = \PHPCompiler\ext\filter\FilterUrlValidate::isValid($c);
            $this->assertSame($vm, $aot, 'mismatch for '.$c);
            $helper = \PHPCompiler\ext\filter\FilterUrlJitHelper::validate($c);
            $this->assertSame($vm ? $c : null, $helper, 'helper mismatch for '.$c);
        }
        $this->assertSame(1, \PHPCompiler\ext\filter\FilterUrlValidate::isValidInt('https://example.com/a'));
        $this->assertSame(0, \PHPCompiler\ext\filter\FilterUrlValidate::isValidInt('not a url'));
    }

    public function testSpineBundleIncludesFilterUrlValidate(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FilterUrlValidate.php', $spine);
        $this->assertStringContainsString('FilterUrlJitHelper.php', $spine);
        $this->assertStringContainsString('StringFilterUrl.php', $spine);
    }
}
