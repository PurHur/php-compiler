<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** version_compare JIT routes through VersionCompareJitHelper + JitVmHelperLink (#9813, #21706). */
final class VersionCompareJitRuntimeShrinkTest extends TestCase
{
    public function testVersionCompareJitHelperDelegatesToVmInfo(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/VersionCompareJitHelper.php');
        $this->assertStringContainsString('VmInfo::version_compare', $source);
    }

    public function testStringVersionCompareRoutesThroughVersionCompareJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVersionCompare.php');
        $this->assertStringContainsString('VersionCompareJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $source);
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
        $this->assertSame(-1, \PHPCompiler\ext\standard\VersionCompareJitHelper::compare('1.0.0', '1.0.1'));
        $this->assertSame(0, \PHPCompiler\ext\standard\VersionCompareJitHelper::compare('1.0.0', '1.0.0'));
        $this->assertSame(-1, \PHPCompiler\ext\standard\VersionCompareJitHelper::compare('1.0.0-dev', '1.0.0'));
        $this->assertSame(-1, \PHPCompiler\ext\standard\VersionCompareJitHelper::compare('8.2.0', '8.10.0'));
        $this->assertSame(-1, \PHPCompiler\ext\standard\VersionCompareJitHelper::compare('1.2', '1.10'));
    }
}
