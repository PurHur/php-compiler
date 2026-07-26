<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * StringPrintR: embed + standalone php-in-PHP bridge via JitVmHelperLink (#9190, #13240, #16565, #22668).
 */
final class PrintRRuntimeShrinkTest extends TestCase
{
    public function testStringPrintRUsesPrintRJitHelperForEmbedAndStandalone(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPrintR.php');
        $this->assertStringContainsString('PrintRJitHelper', $source);
        $this->assertStringNotContainsString('StringPrintRJit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NESTED_HELPER_SOURCES', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringPrintRJit.php');
    }

    public function testPrintRJitHelperDelegatesToVmPrintR(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PrintRJitHelper.php');
        $this->assertStringContainsString('VmPrintR::formatVariable', $source);
        // Standalone AOT resolves sg_vm_context — Superglobals alone is null (#17391 / #23540).
        $this->assertStringContainsString('VmActiveContextJitHelper::resolve', $source);
    }

    public function testStringPrintRPublishesActiveContextAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPrintR.php');
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('VmActiveContextLlvm::ensureAbi', $source);
        $this->assertStringContainsString('NestedVmActiveContextLlvm::ensureMethod', $source);
    }

    public function testPrintRBuiltinUsesStringPrintRBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPrintR.php');
        $this->assertStringContainsString('StringPrintR', $source);
    }

    /** Issue #9190 / #13240: spine must require PrintRJitHelper + thin bridge. */
    public function testSpineBundleIncludesPrintRPhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PrintRJitHelper.php', $spine);
        $this->assertStringContainsString('StringPrintR.php', $spine);
        $this->assertStringContainsString('VmPrintR.php', $spine);
        $this->assertStringNotContainsString('StringPrintRJit.php', $spine);
    }
}
