<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringVarDump JIT/AOT path uses VarDumpJitHelper PHP, not StringVarDumpJit monolith (#9195). */
final class VarDumpRuntimeShrinkTest extends TestCase
{
    public function testStringVarDumpUsesVarDumpJitHelperForJitPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVarDump.php');
        $this->assertStringContainsString('VarDumpJitHelper', $source);
        $this->assertStringContainsString('StringVarDumpJit', $source);
        $this->assertLessThan(160, \substr_count($source, "\n"), 'StringVarDump must be a thin bridge (#9195)');
    }

    public function testVarDumpJitHelperDelegatesToVmVarDump(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VarDumpJitHelper.php');
        $this->assertStringContainsString('VmVarDump::dumpVariable', $source);
    }

    public function testVarDumpBuiltinUsesStringVarDumpNotMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitVarDump.php');
        $this->assertStringContainsString('StringVarDump', $source);
        $this->assertStringNotContainsString('StringVarDumpJit', $source);
    }

    /** Issue #9195: spine must require VarDumpJitHelper + thin bridge, keep standalone LLVM fallback. */
    public function testSpineBundleIncludesVarDumpPhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('VarDumpJitHelper.php', $spine);
        $this->assertStringContainsString('StringVarDump.php', $spine);
        $this->assertStringContainsString('VmVarDump.php', $spine);
        $this->assertStringContainsString('StringVarDumpJit.php', $spine);
    }
}
