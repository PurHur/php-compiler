<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ProcessRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5348 / #12950: process helpers LLVM replaces phpc_process.c; standalone uses ProcessJitHelper bridges.
 */
final class ProcessRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesPhpcProcessC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_process.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_process.c', $linker);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/ProcessRuntime.php');
        $this->assertStringContainsString('__compiler_shell_exec', $runtime);
        $this->assertStringContainsString('ProcessJitHelper', $runtime);
        $this->assertStringNotContainsString('ProcessStandaloneLlvm', $runtime);
        $this->assertStringNotContainsString('emitShellExec', $runtime);
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/ProcessStandaloneLlvm.php');
    }

    /**
     * @group aot-lint
     */
    public function testEnsureLinkedDefinesProcessRuntimeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ProcessRuntime::ensureLinked($ctx);

        foreach (
            [
                '__compiler_shell_exec',
                '__compiler_escapeshellarg',
                '__compiler_escapeshellcmd',
                '__compiler_phpc_run_command',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
