<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * filter_var FILTER_VALIDATE_IP JIT routes through FilterIpValidate PHP
 * via JitVmHelperLink::ensureCompiled (#4403 / #24650 / #27207 / peer #27068).
 */
final class FilterIpRuntimeShrinkTest extends TestCase
{
    public function testFilterIpValidateIsSelfContained(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/FilterIpValidate.php');
        $this->assertStringContainsString('function isValidInt', $source);
        $this->assertStringNotContainsString('preg_match(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\ext\\standard', $source);
    }

    public function testStringFilterIpRoutesThroughFilterIpValidate(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFilterIp.php');
        $this->assertStringContainsString('FilterIpValidate::isValidInt', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(160, \substr_count($source, "\n"), 'StringFilterIp must be a thin bridge');
    }

    public function testJitFilterRoutesThroughStringFilterIp(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('StringFilterIp::ensureLinked', $source);
        $this->assertStringContainsString('VmFilter::isValidIpAddress', $source);
    }

    public function testFilterIpValidateSemanticsMatchVmFilter(): void
    {
        $cases = ['127.0.0.1', '999.0.0.1', '::1', 'not-an-ip', '192.168.0.1'];
        foreach ($cases as $c) {
            $vm = \PHPCompiler\ext\filter\VmFilter::isValidIpAddress($c);
            $aot = \PHPCompiler\ext\filter\FilterIpValidate::isValid($c);
            $this->assertSame($vm, $aot, 'mismatch for '.$c);
            $helper = \PHPCompiler\ext\filter\FilterIpJitHelper::validate($c);
            $this->assertSame($vm ? $c : null, $helper, 'helper mismatch for '.$c);
        }
        $this->assertSame(1, \PHPCompiler\ext\filter\FilterIpValidate::isValidInt('127.0.0.1'));
        $this->assertSame(0, \PHPCompiler\ext\filter\FilterIpValidate::isValidInt('999.0.0.1'));
    }

    /** #29009 — NestedJIT path rejects documentation prefix under NO_RES_RANGE. */
    public function testFilterIpValidateNoResRangeDocumentationPrefix(): void
    {
        $flag = 0x00400000; // FILTER_FLAG_NO_RES_RANGE
        $this->assertSame(0, \PHPCompiler\ext\filter\FilterIpValidate::isValidInt('2001:db8::1', $flag));
        $this->assertSame(0, \PHPCompiler\ext\filter\FilterIpValidate::isValidInt('2001:db8:1::', $flag));
        $this->assertSame(0, \PHPCompiler\ext\filter\FilterIpValidate::isValidInt('fe80::1', $flag));
        $this->assertSame(1, \PHPCompiler\ext\filter\FilterIpValidate::isValidInt('2001:4860:4860::8888', $flag));
        $this->assertSame(1, \PHPCompiler\ext\filter\FilterIpValidate::isValidInt('2001:db8::1', 0));
        $this->assertFalse(\PHPCompiler\ext\filter\VmFilter::isValidIpAddress('2001:db8::1', $flag));
        $this->assertSame(
            \PHPCompiler\ext\filter\VmFilter::isValidIpAddress('2001:db8::1', $flag),
            \PHPCompiler\ext\filter\FilterIpValidate::isValid('2001:db8::1', $flag)
        );
    }

    public function testSpineBundleIncludesFilterIpValidate(): void
    {
        $spine = (string) \file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FilterIpValidate.php', $spine);
        $this->assertStringContainsString('FilterIpJitHelper.php', $spine);
        $this->assertStringContainsString('StringFilterIp.php', $spine);
    }
}
