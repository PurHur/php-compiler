<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\NaturalCompareJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * strnatcmp/strnatcasecmp JIT/AOT: LLVM in JitNaturalCompareKernel (#30088 quarantine).
 * NestedJIT NaturalCompareJitHelper still unsafe under thin AOT (#26975).
 */
final class NaturalCompareRuntimeShrinkTest extends TestCase
{
    public function testStringNaturalCompareDelegatesToExtKernelNotBuiltinLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringNaturalCompare.php');
        $this->assertStringContainsString('JitNaturalCompareKernel::implementStrnatcmp', $source);
        $this->assertStringContainsString('JitNaturalCompareKernel::implementStrnatcasecmp', $source);
        $this->assertStringNotContainsString('StringNaturalCompareJit', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringNaturalCompareJit.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitNaturalCompareKernel.php');

        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitNaturalCompareKernel.php');
        $this->assertStringContainsString('implementNamed', $kernel);
        $this->assertStringContainsString('nat_main_head', $kernel);
        $this->assertStringContainsString('#30088', $kernel);

        $strnatcmp = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrnatcmp.php');
        $this->assertStringContainsString('StringNaturalCompare::ensureStrnatcmpLinked', $strnatcmp);
    }

    public function testNaturalCompareJitHelperMatchesVmStringForZendHostedTests(): void
    {
        $this->assertSame(1, NaturalCompareJitHelper::strnatcmpArgv('img10', 'img2'));
        $this->assertSame(1, VmString::strnatcmp('img10', 'img2'));
        $this->assertSame(0, NaturalCompareJitHelper::strnatcasecmpArgv('ABC', 'abc'));
        $this->assertSame(0, VmString::strnatcasecmp('ABC', 'abc'));
        $this->assertSame(VmString::strnatcmp('img2', 'img10'), NaturalCompareJitHelper::strnatcmpArgv('img2', 'img10'));
    }

    public function testSpineBundleIncludesNaturalCompareKernelNotBuiltinLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StringNaturalCompare.php', $spine);
        $this->assertStringContainsString('JitNaturalCompareKernel.php', $spine);
        $this->assertStringContainsString('NaturalCompareJitHelper.php', $spine);
        $this->assertStringNotContainsString('StringNaturalCompareJit.php', $spine);
    }
}
