<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringVarDump JIT/AOT path uses VarDumpJitHelper PHP, not StringVarDumpJit monolith (#9195, #13241). */
final class VarDumpRuntimeShrinkTest extends TestCase
{
    public function testStringVarDumpUsesVarDumpJitHelperForJitPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVarDump.php');
        $this->assertStringContainsString('VarDumpJitHelper', $source);
        $this->assertStringNotContainsString('StringVarDumpJit', $source);
        $this->assertLessThan(160, \substr_count($source, "\n"), 'StringVarDump must be a thin bridge (#9195)');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringVarDumpJit.php');
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

    public function testStandaloneUsesSamePhpBridgeAsEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVarDump.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('self::implement($context)', $source);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $source);
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
