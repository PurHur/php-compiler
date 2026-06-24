<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringPrintR;
use PHPCompiler\JIT\Builtin\StringVarDump;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9195 / #9190: AOT standalone var_dump/print_r via PHP JitHelpers, not LLVM monoliths.
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
        $this->assertStringNotContainsString(
            'is not implemented for JIT',
            (string) file_get_contents(__DIR__.'/../../../ext/standard/print_r.php')
        );
        $this->assertStringNotContainsString(
            'is not implemented for JIT',
            (string) file_get_contents(__DIR__.'/../../../ext/standard/var_dump_.php')
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEnsureStandaloneDefinesVarDumpRuntimeHelper(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringVarDump::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__compiler_var_dump');
        $this->assertNotNull($fn, '__compiler_var_dump must be linked for standalone AOT');
        $this->assertGreaterThan(0, $fn->countBasicBlocks(), '__compiler_var_dump must have LLVM body');

        $this->assertNull(
            $ctx->functions[\strtolower('PHPCompiler\\ext\\standard\\VarDumpJitHelper::dumpValue')] ?? null,
            'standalone AOT still uses StringVarDumpJit LLVM monolith (#9195)'
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEnsureStandaloneDefinesPrintRRuntimeHelper(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringPrintR::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__compiler_print_r');
        $this->assertNotNull($fn, '__compiler_print_r must be linked for standalone AOT');
        $this->assertGreaterThan(0, $fn->countBasicBlocks(), '__compiler_print_r must have LLVM body');

        $this->assertNull(
            $ctx->functions[\strtolower('PHPCompiler\\ext\\standard\\PrintRJitHelper::formatValue')] ?? null,
            'standalone AOT still uses StringPrintRJit LLVM monolith (#9190)'
        );
    }
}
