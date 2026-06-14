<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #5190: var_export LLVM helpers replace phpc_var_export.c.
 *
 * @group aot-lint
 */
final class StringVarExportRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesPhpcVarExportC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_var_export.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_var_export.c', $linker);
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringVarExportJit.php');
        $this->assertStringContainsString('__compiler_var_export', $jit);
        $this->assertStringContainsString('isnan', $jit);
        $this->assertStringContainsString("'NAN'", $jit);
        $this->assertStringContainsString("'INF'", $jit);
    }
}
