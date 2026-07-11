<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #5190 / #9189 / #13349: var_export LLVM helpers + JIT PHP bridge.
 *
 * @group aot-lint
 */
final class StringVarExportRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesPhpcVarExportCAndStringVarExportJit(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_var_export.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringVarExportJit.php');

        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_var_export.c', $linker);

        $bridge = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringVarExport.php');
        $this->assertStringContainsString('VarExportJitHelper', $bridge);
        $this->assertStringNotContainsString('StringVarExportJit', $bridge);
    }
}
