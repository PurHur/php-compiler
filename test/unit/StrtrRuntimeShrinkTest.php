<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringStrtr JIT/AOT path uses Strtr*JitHelper PHP, not StringStrtrJit LLVM monolith (#9392). */
final class StrtrRuntimeShrinkTest extends TestCase
{
    public function testStringStrtrUsesJitHelpersForJitPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrtr.php');
        $this->assertStringContainsString('StrtrTwoStringJitHelper', $source);
        $this->assertStringContainsString('StrtrArrayJitHelper', $source);
        $this->assertStringNotContainsString('StringStrtrJit', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringStrtrJit.php');
    }

    public function testStrtrJitHelpersDelegateToVmString(): void
    {
        $twoString = (string) file_get_contents(__DIR__.'/../../ext/standard/StrtrTwoStringJitHelper.php');
        $this->assertStringContainsString('VmString::strtr(', $twoString);

        $array = (string) file_get_contents(__DIR__.'/../../ext/standard/StrtrArrayJitHelper.php');
        $this->assertStringContainsString('VmString::strtrArray', $array);
    }

    public function testStandaloneArrayQuarantineDocumented(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrtr.php');
        $this->assertStringContainsString('StringStrtrStandaloneLlvm', $source);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StringStrtrStandaloneLlvm.php');
    }

    public function testSpineBundleOmitsDeletedStringStrtrJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('StringStrtrJit.php', $spine);
        $this->assertStringContainsString('StrtrTwoStringJitHelper.php', $spine);
        $this->assertStringContainsString('StrtrArrayJitHelper.php', $spine);
    }
}
