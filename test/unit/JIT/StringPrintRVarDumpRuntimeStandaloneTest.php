<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9195 / #9190 / #13240 / #13241 / #16565: AOT var_dump/print_r paths.
 *
 * @group aot-lint
 */
final class StringPrintRVarDumpRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkUsesPhpJitHelpersNotC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_print_r.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_var_dump.c');
        $printRBridge = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringPrintR.php');
        $varDumpBridge = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringVarDump.php');
        $this->assertStringContainsString('PrintRJitHelper', $printRBridge);
        $this->assertStringContainsString('VarDumpJitHelper', $varDumpBridge);
        $this->assertFileExists(__DIR__.'/../../../lib/JIT/Builtin/StringPrintRJit.php');
        $this->assertFileExists(__DIR__.'/../../../lib/JIT/Builtin/StringVarDumpJit.php');
        $this->assertStringNotContainsString(
            'is not implemented for JIT',
            (string) file_get_contents(__DIR__.'/../../../ext/standard/print_r.php')
        );
        $this->assertStringNotContainsString(
            'is not implemented for JIT',
            (string) file_get_contents(__DIR__.'/../../../ext/standard/var_dump_.php')
        );
    }

    public function testStandaloneUsesLlvmMonolithNotNestedPhpHelper(): void
    {
        foreach (['StringPrintR.php', 'StringVarDump.php'] as $bridge) {
            $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/'.$bridge);
            $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $source, $bridge);
            $this->assertStringContainsString('::ensureLinked', $source, $bridge);
        }
    }
}
