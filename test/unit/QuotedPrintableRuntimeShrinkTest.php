<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** quoted_printable_* C runtime shrink (#5376). */
final class QuotedPrintableRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testPhpcQuotPrintCRuntimeRemovedFromLinker(): void
    {
        $linker = file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_quot_print.c', $linker);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_quot_print.c');
    }

    public function testJitLoweringUsesPhpQuotPrintJitOnly(): void
    {
        $encode = file_get_contents($this->repoRoot.'/ext/standard/JitQuotedPrintableEncode.php');
        $bridge = file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringQuotPrint.php');
        $jit = file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringQuotPrintJit.php');
        $this->assertIsString($encode);
        $this->assertIsString($bridge);
        $this->assertIsString($jit);
        $this->assertStringContainsString('StringQuotPrint::ensureLinked', $encode);
        $this->assertStringContainsString('__compiler_quoted_printable_encode', $encode);
        $this->assertStringContainsString('StringQuotPrintJit::implement', $bridge);
        $this->assertStringContainsString('__compiler_quoted_printable_encode', $jit);
        $this->assertStringContainsString('__compiler_quoted_printable_decode', $jit);
        $this->assertStringNotContainsString('ensureBitcode', $bridge);
        $this->assertStringNotContainsString('ensureBitcode', $jit);
    }
}
