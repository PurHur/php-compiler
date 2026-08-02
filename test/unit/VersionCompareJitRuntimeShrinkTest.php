<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** version_compare JIT routes through VersionCompareJitHelper + JitVmHelperLink (#9813, #21706). */
final class VersionCompareJitRuntimeShrinkTest extends TestCase
{
    public function testVersionCompareJitHelperIsNestedJitSelfContained(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/VersionCompareJitHelper.php');
        // NestedJIT stubs cross-class VmInfo under thin AOT (#26866; peer #26884).
        $this->assertStringNotContainsString('VmInfo::', $source);
        $this->assertStringContainsString('substr(', $source);
        $this->assertStringContainsString('#26866', $source);
    }

    public function testStringVersionCompareRoutesThroughVersionCompareJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVersionCompare.php');
        $this->assertStringContainsString('VersionCompareJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('emitCanonicalizeVersion', $source);
        $this->assertStringNotContainsString('emitCompareSpecialForms', $source);
        $this->assertStringNotContainsString('emitVersionCompareChars', $source);
        $this->assertStringNotContainsString('SPECIAL_FORMS', $source);

        $jitShim = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVersionCompareJit.php');
        $this->assertLessThan(20, \substr_count($jitShim, "\n"), 'StringVersionCompareJit must be a thin shim');
    }

    public function testJitInfoRoutesThroughStringVersionCompare(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitInfo.php');
        $this->assertStringContainsString('StringVersionCompare::ensureLinked', $source);
    }

    public function testVersionCompareJitHelperSemanticsMatchVmInfo(): void
    {
        // Helper ABI encodes -1/0/1 as 0/1/2 for NestedJIT thin-AOT (#26866).
        // Digit.dot forms (no canonicalize) — separator forms fold via JitInfo+VmInfo.
        $this->assertSame(0, \PHPCompiler\ext\standard\VersionCompareJitHelper::compare('1.0.0', '1.0.1'));
        $this->assertSame(1, \PHPCompiler\ext\standard\VersionCompareJitHelper::compare('1.0.0', '1.0.0'));
        $this->assertSame(0, \PHPCompiler\ext\standard\VersionCompareJitHelper::compare('8.2.0', '8.10.0'));
        $this->assertSame(0, \PHPCompiler\ext\standard\VersionCompareJitHelper::compare('1.2', '1.10'));
        $this->assertSame(2, \PHPCompiler\ext\standard\VersionCompareJitHelper::compare('1.0.1', '1.0.0'));
        $this->assertSame(0, \PHPCompiler\ext\standard\VersionCompareJitHelper::compare('8.2.0', '8.3.0'));
    }

    public function testStringVersionCompareUsesCustomStringStarBridge(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVersionCompare.php');
        $this->assertStringContainsString('extractLongFromHelperResult', $source);
        $this->assertStringContainsString('constInt(1, false)', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $source);
    }
}
