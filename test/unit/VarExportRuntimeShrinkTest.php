<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringVarExport JIT/AOT path uses VarExportJitHelper PHP + ext kernel for user-script (#9189, #13349, #19430). */
final class VarExportRuntimeShrinkTest extends TestCase
{
    public function testStringVarExportUsesVarExportJitHelperForJitPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVarExport.php');
        $this->assertStringContainsString('VarExportJitHelper', $source);
        $this->assertStringContainsString('JitVarExportKernel', $source);
        $this->assertStringContainsString('UserScriptAotDeferNestedJit::shouldDefer', $source);
        $this->assertStringNotContainsString('StringVarExportJit', $source);
        $this->assertStringNotContainsString('StringVarExportUserScriptLlvm', $source);
        $this->assertLessThan(160, \substr_count($source, "\n"), 'StringVarExport must be a thin bridge (#9189)');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringVarExportUserScriptLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitVarExportKernel.php');
    }

    public function testVarExportJitHelperDelegatesToVmVarExport(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VarExportJitHelper.php');
        $this->assertStringContainsString('VmVarExport::formatVariable', $source);
    }

    public function testVarExportBuiltinUsesJitVarExportNotMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/var_export.php');
        $this->assertStringContainsString('JitVarExport', $source);
        $this->assertStringNotContainsString('StringVarExportJit', $source);
        $this->assertStringContainsString('VmVarExport::formatVariable', $source);
    }

    public function testSpineBundleIncludesVarExportPhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('VarExportJitHelper.php', $spine);
        $this->assertStringContainsString('StringVarExport.php', $spine);
        $this->assertStringContainsString('JitVarExportKernel.php', $spine);
        $this->assertStringContainsString('VmVarExport.php', $spine);
        $this->assertStringNotContainsString('StringVarExportJit.php', $spine);
        $this->assertStringNotContainsString('StringVarExportUserScriptLlvm.php', $spine);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringVarExportJit.php');
    }
}
