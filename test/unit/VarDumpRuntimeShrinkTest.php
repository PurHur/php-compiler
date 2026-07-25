<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * StringVarDump NestedJIT via JitVmHelperLink::ensureCompiled (#23143 / peer #22668).
 */
final class VarDumpRuntimeShrinkTest extends TestCase
{
    public function testStringVarDumpUsesVarDumpJitHelperForEmbedAndStandalone(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVarDump.php');
        $this->assertStringContainsString('VarDumpJitHelper', $source);
        $this->assertStringNotContainsString('StringVarDumpJit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringVarDumpJit.php');
    }

    public function testVarDumpJitHelperDelegatesToVmVarDump(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VarDumpJitHelper.php');
        $this->assertStringContainsString('VmVarDump::dumpVariable', $source);
        $this->assertStringContainsString('formatVariableValue', $source);
        $this->assertStringNotContainsString('function dumpValue', $source);
    }

    public function testVarDumpBuiltinUsesStringVarDumpBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitVarDump.php');
        $this->assertStringContainsString('StringVarDump', $source);
    }

    /** Issue #9195 / #13241: spine must require VarDumpJitHelper + thin bridge. */
    public function testSpineBundleIncludesVarDumpPhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('VarDumpJitHelper.php', $spine);
        $this->assertStringContainsString('StringVarDump.php', $spine);
        $this->assertStringContainsString('VmVarDump.php', $spine);
        $this->assertStringNotContainsString('StringVarDumpJit.php', $spine);
    }
}
