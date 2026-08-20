<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on quoted_printable_* ABI shells from Builtin\Type (#32882).
 *
 * NestedJIT/AOT bridge stays StringQuotPrint.
 * Runtime owner declares module-locally via JitVmHelperLink::ensureBridge so leftover
 * Type empty decls cannot mint quoted_printable_encode.1 (#31894 / #32122).
 */
final class TypeDeadQuotPrintAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_quoted_printable_encode',
            '__compiler_quoted_printable_decode',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnQuotPrintAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32882', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32882)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32882)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_readfile'", $type);
        $this->assertStringContainsString('StringQuotPrint::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresQuotPrintAbisModuleLocally(): void
    {
        $svc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringQuotPrint.php');
        $this->assertStringContainsString('#32882', $svc);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $svc);
        $this->assertStringContainsString('getNamedFunction', $svc);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertStringContainsString($sym, $svc, "{$sym} must remain owned by StringQuotPrint (#32882)");
        }
    }

    public function testTypeInitializeStillEnsureLinksQuotPrintRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringQuotPrint::ensureLinked($this->context)', $type);
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/QuotPrintJitHelper.php');
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/lib/AOT/runtime/quoted_printable.c'
        );
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/runtime/quoted_printable.c'
        );
    }
}
