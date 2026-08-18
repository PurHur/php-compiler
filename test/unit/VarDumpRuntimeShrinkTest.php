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
        $this->assertStringNotContainsString('NESTED_HELPER_SOURCES', $source);
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
        $this->assertStringContainsString('VmVarDump::tryDumpWithoutVm', $source);
        $this->assertStringContainsString('formatVariableValue', $source);
        $this->assertStringNotContainsString('function dumpValue', $source);
        // Non-scalar path still resolves sg_vm_context (#17391 / #23540).
        $this->assertStringContainsString('VmActiveContextJitHelper::resolve', $source);
    }

    public function testVmVarDumpExposesScalarDumpWithoutVm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmVarDump.php');
        $this->assertStringContainsString('tryDumpWithoutVm', $source);
        $this->assertStringContainsString('tryWriteScalarPayload', $source);
    }

    public function testStringVarDumpPublishesActiveContextAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVarDump.php');
        // Embed path still publishes sg_vm_context; thin AOT uses scalar IR bridge (#23540).
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('VmActiveContextLlvm::ensureAbi', $source);
        $this->assertStringContainsString('NestedVmActiveContextLlvm::ensureMethod', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('implementThinScalarBridge', $source);
        $this->assertStringContainsString('formatVarDumpH', $source);
        $this->assertStringContainsString('serialize_precision', $source);
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
