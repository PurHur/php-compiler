<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #6709: print_r/var_dump LLVM helpers — no C runtime debug formatters.
 *
 * @group aot-lint
 */
final class StringPrintRVarDumpRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkUsesPhpJitHelpersNotC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_print_r.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_var_dump.c');
        $printR = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringPrintRJit.php');
        $varDump = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringVarDumpJit.php');
        $this->assertStringContainsString('__compiler_print_r', $printR);
        $this->assertStringContainsString('__compiler_var_dump', $varDump);
        $this->assertStringNotContainsString(
            'is not implemented for JIT',
            (string) file_get_contents(__DIR__.'/../../../ext/standard/print_r.php')
        );
        $this->assertStringNotContainsString(
            'is not implemented for JIT',
            (string) file_get_contents(__DIR__.'/../../../ext/standard/var_dump_.php')
        );
    }
}
