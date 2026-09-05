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
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('compileHelperFile', $source);
        $this->assertStringNotContainsString('StringStrtrJit', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringStrtrJit.php');
    }

    public function testStrtrJitHelpersAreNestedJitSafe(): void
    {
        // #36382 / peer #27056 — two-string form must be self-contained (no VmString).
        // NestedJIT stubs VmString::strtr to the subject (or SEGVs on constant $from/$to).
        $twoString = (string) file_get_contents(__DIR__.'/../../ext/standard/StrtrTwoStringJitHelper.php');
        $this->assertStringNotContainsString('VmString::', $twoString);
        $this->assertStringContainsString('\\strlen', $twoString);
        $this->assertStringContainsString('strtrTwoString', $twoString);

        // #27056 — array form must be self-contained (no VmString) for thin AOT NestedJIT.
        $array = (string) file_get_contents(__DIR__.'/../../ext/standard/StrtrArrayJitHelper.php');
        $this->assertStringNotContainsString('VmString::', $array);
        $this->assertStringContainsString('exportKeyValuePairs', $array);
        $this->assertStringContainsString('$pair[0]', $array);
        $this->assertStringContainsString('$pair[1]', $array);
        $this->assertStringContainsString('(string) $pair[0]', $array);
        $this->assertStringNotContainsString('as [$', $array);
        $this->assertStringNotContainsString('->toString()', $array);
    }

    public function testStringStrtrStandaloneLlvmDeleted(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrtr.php');
        $this->assertStringNotContainsString('StringStrtrStandaloneLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringStrtrStandaloneLlvm.php');
    }

    public function testSpineBundleOmitsDeletedStringStrtrJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('StringStrtrJit.php', $spine);
        $this->assertStringNotContainsString('StringStrtrStandaloneLlvm.php', $spine);
        $this->assertStringContainsString('StrtrTwoStringJitHelper.php', $spine);
        $this->assertStringContainsString('StrtrArrayJitHelper.php', $spine);
    }
}
