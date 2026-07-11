<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\NaturalCompareJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strnatcmp/strnatcasecmp JIT routes through NaturalCompareJitHelper PHP (#13535). */
final class NaturalCompareRuntimeShrinkTest extends TestCase
{
    public function testStringNaturalCompareUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringNaturalCompare.php');
        $this->assertStringContainsString('NaturalCompareJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringNaturalCompareJit.php');

        $strnatcmp = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrnatcmp.php');
        $this->assertStringContainsString('StringNaturalCompare::ensureStrnatcmpLinked', $strnatcmp);
        $this->assertStringNotContainsString('StringNaturalCompareJit', $strnatcmp);
    }

    public function testNaturalCompareJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/NaturalCompareJitHelper.php');
        $this->assertStringContainsString('VmString::strnatcmp', $source);
        $this->assertStringContainsString('VmString::strnatcasecmp', $source);

        $this->assertSame(1, NaturalCompareJitHelper::strnatcmpArgv('img10', 'img2'));
        $this->assertSame(1, VmString::strnatcmp('img10', 'img2'));
        $this->assertSame(0, NaturalCompareJitHelper::strnatcasecmpArgv('ABC', 'abc'));
        $this->assertSame(0, VmString::strnatcasecmp('ABC', 'abc'));
    }

    public function testSpineBundleIncludesNaturalCompareJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('StringNaturalCompareJit.php', $spine);
        $this->assertStringContainsString('NaturalCompareJitHelper.php', $spine);
        $this->assertStringContainsString('StringNaturalCompare.php', $spine);
    }
}
