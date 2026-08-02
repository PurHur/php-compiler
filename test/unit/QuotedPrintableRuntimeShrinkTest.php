<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** quoted_printable_* JIT via QuotPrintJitHelper + JitVmHelperLink::ensureBridge (#9910, #24620, #26899). */
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
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        // NestedJIT-self-contained — no VmString call (would ExternalMethod-stub → AOT segfault #26899).
        $this->assertStringNotContainsString('VmString::', $helper);
        $this->assertStringContainsString('byteOrd', $helper);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $bridge);
        $this->assertStringNotContainsString('parseAndCompile', $bridge);
        $this->assertStringNotContainsString('new JIT(', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('emitEncode', $bridge);
        $this->assertStringNotContainsString('QPRINT_MAXL', $bridge);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/StringQuotPrintJit.php');
    }

    public function testQuotPrintJitHelperSemanticsMatchVmString(): void
    {
        $cases = ["foo\r\nbar", 'hello', 'a=b', '', "line \r\n", str_repeat('x', 80)];
        foreach ($cases as $raw) {
            $this->assertSame(
                \PHPCompiler\ext\standard\VmString::quoted_printable_encode($raw),
                \PHPCompiler\ext\standard\QuotPrintJitHelper::encode($raw),
                'encode: '.var_export($raw, true)
            );
            $encoded = \PHPCompiler\ext\standard\QuotPrintJitHelper::encode($raw);
            $this->assertSame(
                \PHPCompiler\ext\standard\VmString::quoted_printable_decode($encoded),
                \PHPCompiler\ext\standard\QuotPrintJitHelper::decode($encoded),
                'decode of encode: '.var_export($raw, true)
            );
        }
        // Soft-break / soft-line decode path
        $this->assertSame(
            \PHPCompiler\ext\standard\VmString::quoted_printable_decode("a=\r\nb"),
            \PHPCompiler\ext\standard\QuotPrintJitHelper::decode("a=\r\nb")
        );
    }
}
