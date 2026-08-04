<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** StringVarExport: NestedJIT helper for embed; thin scalar AOT bridge (#9189, #20589, #26855). */
final class VarExportRuntimeShrinkTest extends TestCase
{
    public function testStringVarExportUsesJitHelperNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringVarExport.php');
        $this->assertStringContainsString('VarExportJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('var_export_bridge_entry', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('implementThinScalarBridge', $source);
        $this->assertStringContainsString('__compiler_var_export_format_string', $source);
        $this->assertStringContainsString('#27574', $source);
        $this->assertStringNotContainsString('JitVarExportKernel', $source);
        $this->assertStringNotContainsString('var_export_user_script_bridge', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StringVarExportJit', $source);
        $this->assertStringNotContainsString('StringVarExportUserScriptLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitVarExportKernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringVarExportUserScriptLlvm.php');
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
        $this->assertStringNotContainsString('JitVarExportKernel.php', $spine);
        $this->assertStringContainsString('VmVarExport.php', $spine);
        $this->assertStringNotContainsString('StringVarExportJit.php', $spine);
        $this->assertStringNotContainsString('StringVarExportUserScriptLlvm.php', $spine);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringVarExportJit.php');
    }
}
