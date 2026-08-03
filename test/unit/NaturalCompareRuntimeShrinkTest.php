<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\NaturalCompareJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strnatcmp/strnatcasecmp JIT/AOT use LLVM StringNaturalCompareJit (#5517, #26975). */
final class NaturalCompareRuntimeShrinkTest extends TestCase
{
    public function testStringNaturalCompareUsesLlvmNotNestedJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringNaturalCompare.php');
        $this->assertStringContainsString('StringNaturalCompareJit::implementStrnatcmp', $source);
        $this->assertStringContainsString('StringNaturalCompareJit::implementStrnatcasecmp', $source);
        $this->assertStringNotContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString('NaturalCompareJitHelper::', $source);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StringNaturalCompareJit.php');

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

    public function testSpineBundleIncludesNaturalCompareLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StringNaturalCompareJit.php', $spine);
        $this->assertStringContainsString('StringNaturalCompare.php', $spine);
        $this->assertStringContainsString('NaturalCompareJitHelper.php', $spine);
    }
}
