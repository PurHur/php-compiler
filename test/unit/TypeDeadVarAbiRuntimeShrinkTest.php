<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on var_export/print_r/var_dump ABI shells from Builtin\Type (#32941).
 *
 * NestedJIT/AOT bridges stay StringVarExport / StringPrintR / StringVarDump + Jit helpers.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover
 * Type empty decls cannot mint var_export.1 / print_r.1 / var_dump.1 (#31894 / #32122).
 */
final class TypeDeadVarAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_var_export',
            '__compiler_print_r',
            '__compiler_var_dump',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnVarAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32941', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#32941)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#32941)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        $this->assertStringContainsString("registerFunction('__compiler_tmpfile'", $type);
        $this->assertStringContainsString('StringVarExport::ensureLinked', $type);
        $this->assertStringContainsString('StringPrintR::ensureLinked', $type);
        $this->assertStringContainsString('StringVarDump::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresVarAbisModuleLocally(): void
    {
        $export = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVarExport.php');
        $printR = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPrintR.php');
        $dump = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVarDump.php');
        $this->assertStringContainsString('#32941', $export);
        $this->assertStringContainsString('#32941', $printR);
        $this->assertStringContainsString('#32941', $dump);
        $this->assertStringContainsString('getNamedFunction', $export);
        $this->assertStringContainsString('getNamedFunction', $printR);
        $this->assertStringContainsString('getNamedFunction', $dump);
        $this->assertStringContainsString('__compiler_var_export', $export);
        $this->assertStringContainsString('__compiler_print_r', $printR);
        $this->assertStringContainsString('__compiler_var_dump', $dump);
        $this->assertFileExists(__DIR__.'/../../ext/standard/VarExportJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/PrintRJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/VarDumpJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitVarExport.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitPrintR.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitVarDump.php');
    }

    public function testTypeInitializeStillEnsureLinksVarRuntime(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringVarExport::ensureLinked($this->context)', $type);
        $this->assertStringContainsString('StringPrintR::ensureLinked($this->context)', $type);
        $this->assertStringContainsString('StringVarDump::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForVarAbis(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/var_export.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/var_export.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/print_r.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/print_r.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/var_dump.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/var_dump.c');
    }
}
