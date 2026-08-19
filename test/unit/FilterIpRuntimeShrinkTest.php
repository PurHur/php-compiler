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
        $this->assertStringNotContainsString('explode(', $source, 'NestedJIT thin-AOT hazard (#32571)');
        $this->assertStringNotContainsString('str_starts_with(', $source, 'NestedJIT thin-AOT hazard (#32571)');
        $this->assertStringNotContainsString('str_contains(', $source, 'NestedJIT thin-AOT hazard (#32571)');
        $this->assertDoesNotMatchRegularExpression('/private static int \$/', $source, 'mutable static spill (#32571)');
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

    /** #32571 — dispatchConstFilter passes loadFilterFlags() → constInt(0), not null. */
    public function testValidateIpFoldsConstFlagsLikeValidateFloat(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('compileTimeFlagsInt($context, $flags)', $source);
        $this->assertStringContainsString('null !== $lit && null !== $flagsInt', $source);
        $this->assertStringNotContainsString('null !== $lit && null === $flags', $source);
        $this->assertStringNotContainsString('null !== $lit && 0 === $flagsInt', $source);
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

    /**
     * Dynamic filter_var(..., FILTER_VALIDATE_IP) must not SIGSEGV under AOT (#32571).
     *
     * @group llvm
     * @group aot
     */
    public function testAotDynamicFilterValidateIpMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_filter_validate_ip_aot_dynamic.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32571_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>/dev/null';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("'127.0.0.1'\n'::1'\nfalse\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
