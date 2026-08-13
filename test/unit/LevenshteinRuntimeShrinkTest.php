<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\LevenshteinJitHelper;
use PHPCompiler\ext\standard\VmLevenshtein;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * levenshtein() NestedJIT via HELPER_BUNDLE (#23768 / #14648 / #30790).
 * Must route phpc_levenshtein through LevenshteinJitHelper + VmLevenshtein PHP not inline LLVM.
 */
final class LevenshteinRuntimeShrinkTest extends TestCase
{
    public function testStringLevenshteinUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringLevenshtein.php');
        $this->assertStringContainsString('LevenshteinJitHelper', $source);
        $this->assertStringContainsString('VmLevenshtein.php', $source);
        $this->assertStringContainsString('HELPER_BUNDLE', $source);
        $this->assertStringContainsString('ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitLevenshtein.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/levenshtein.php');
        $this->assertStringContainsString('StringLevenshtein::ensureLinked', $builtin);
        $this->assertStringContainsString('phpc_levenshtein', $builtin);
        $this->assertStringNotContainsString('JitLevenshtein', $builtin);
    }

    public function testUserScriptAotForcesNestedJitOfLevenshteinHelper(): void
    {
        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString(
            "phpcompiler\\\\ext\\\\standard\\\\levenshteinjithelper::computeargv",
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT computeArgv — prelinked unit.o SIGSEGVs (#30790)'
        );
    }

    public function testLevenshteinJitHelperDelegatesToVmLevenshtein(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/LevenshteinJitHelper.php');
        $this->assertStringContainsString('VmLevenshtein::compute', $source);
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringNotContainsString('$string1[$', $source);
        $this->assertStringNotContainsString('$string2[$', $source);

        $vm = (string) file_get_contents(__DIR__.'/../../ext/standard/VmLevenshtein.php');
        $this->assertStringContainsString('\\strlen(', $vm);
        $this->assertStringContainsString('\\substr(', $vm);
        $this->assertStringContainsString('$j = $j + 1', $vm);
        // NestedJIT AOT aborts on PHP arrays — DP row is a digit string (#30790).
        $this->assertStringNotContainsString('array_fill', $vm);
        $this->assertStringNotContainsString('$row[]', $vm);

        $this->assertSame(3, LevenshteinJitHelper::computeArgv('kitten', 'sitting', 1, 1, 1));
        $this->assertSame(3, VmLevenshtein::compute('kitten', 'sitting', 1, 1, 1));
        $this->assertSame(3, VmString::levenshtein('kitten', 'sitting'));
        $this->assertSame(0, LevenshteinJitHelper::computeArgv('', '', 1, 1, 1));
        $this->assertSame(3, LevenshteinJitHelper::computeArgv('', 'abc', 1, 1, 1));
        $this->assertSame(1, LevenshteinJitHelper::computeArgv('abc', 'ab', 2, 1, 1));
        $this->assertSame(300, LevenshteinJitHelper::computeArgv(
            \str_repeat('a', 300),
            \str_repeat('b', 300),
            1,
            1,
            1
        ));
    }

    public function testSpineBundleIncludesVmLevenshtein(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitLevenshtein.php', $spine);
        $this->assertStringContainsString('VmLevenshtein.php', $spine);
        $this->assertStringContainsString('LevenshteinJitHelper.php', $spine);
        $this->assertStringContainsString('StringLevenshtein.php', $spine);
    }
}
