<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\LevenshteinJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * levenshtein() NestedJIT via JitVmHelperLink::ensureCompiled (#23768 / peer #23671).
 * Must route phpc_levenshtein through LevenshteinJitHelper PHP not inline LLVM (#14648).
 */
final class LevenshteinRuntimeShrinkTest extends TestCase
{
    public function testStringLevenshteinUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringLevenshtein.php');
        $this->assertStringContainsString('LevenshteinJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
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

    public function testLevenshteinJitHelperIsSelfContainedSsot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/LevenshteinJitHelper.php');
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringContainsString('Same-class only', $source);

        $vm = (string) file_get_contents(__DIR__.'/../../ext/standard/VmString.php');
        $this->assertStringContainsString('LevenshteinJitHelper::computeArgv', $vm);

        $this->assertSame(3, LevenshteinJitHelper::computeArgv('kitten', 'sitting', 1, 1, 1));
        $this->assertSame(3, VmString::levenshtein('kitten', 'sitting'));
        $this->assertSame(300, LevenshteinJitHelper::computeArgv(
            \str_repeat('a', 300),
            \str_repeat('b', 300),
            1,
            1,
            1
        ));
    }

    public function testSpineBundleIncludesLevenshteinJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitLevenshtein.php', $spine);
        $this->assertStringContainsString('LevenshteinJitHelper.php', $spine);
        $this->assertStringContainsString('StringLevenshtein.php', $spine);
    }
}
