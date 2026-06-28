<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringVarExport JIT/AOT path uses VarExportJitHelper PHP, not StringVarExportJit monolith (#9189, #13349). */
final class VarExportRuntimeShrinkTest extends TestCase
{
    public function testStringVarExportUsesVarExportJitHelperForJitPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVarExport.php');
        $this->assertStringContainsString('VarExportJitHelper', $source);
        $this->assertStringNotContainsString('StringVarExportJit', $source);
        $this->assertLessThan(160, \substr_count($source, "\n"), 'StringVarExport must be a thin bridge (#9189)');
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
        $this->assertStringContainsString('VmVarExport.php', $spine);
        $this->assertStringNotContainsString('StringVarExportJit.php', $spine);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringVarExportJit.php');
    }
}
