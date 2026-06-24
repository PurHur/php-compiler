<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** quoted_printable_* JIT routes through QuotPrintJitHelper PHP, not StringQuotPrintJit LLVM (#9910). */
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

    public function testJitLoweringUsesQuotPrintJitHelperNotLlvmMonolith(): void
    {
        $encode = file_get_contents($this->repoRoot.'/ext/standard/JitQuotedPrintableEncode.php');
        $bridge = file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringQuotPrint.php');
        $helper = file_get_contents($this->repoRoot.'/ext/standard/QuotPrintJitHelper.php');
        $this->assertIsString($encode);
        $this->assertIsString($bridge);
        $this->assertIsString($helper);
        $this->assertStringContainsString('StringQuotPrint::ensureLinked', $encode);
        $this->assertStringContainsString('__compiler_quoted_printable_encode', $encode);
        $this->assertStringContainsString('QuotPrintJitHelper', $bridge);
        $this->assertStringContainsString('VmString::quoted_printable_encode', $helper);
        $this->assertStringNotContainsString('emitEncode', $bridge);
        $this->assertStringNotContainsString('QPRINT_MAXL', $bridge);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/StringQuotPrintJit.php');
    }

    public function testQuotPrintJitHelperSemanticsMatchVmString(): void
    {
        $this->assertSame(
            \PHPCompiler\ext\standard\VmString::quoted_printable_encode("foo\r\nbar"),
            \PHPCompiler\ext\standard\QuotPrintJitHelper::encode("foo\r\nbar")
        );
        $encoded = \PHPCompiler\ext\standard\QuotPrintJitHelper::encode('hello');
        $this->assertSame(
            \PHPCompiler\ext\standard\VmString::quoted_printable_decode($encoded),
            \PHPCompiler\ext\standard\QuotPrintJitHelper::decode($encoded)
        );
    }
}
