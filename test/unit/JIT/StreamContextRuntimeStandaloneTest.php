<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamContextRuntime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5392 / #9340 / #19817 / #23049: stream_context LLVM routes through StreamContextJitHelper PHP.
 *
 * @group aot-lint
 */
final class StreamContextRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesStreamContextC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_stream_context.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_stream_context.c', $linker);
        $kernel = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitStreamContextKernel.php');
        $this->assertStringContainsString('__phpc_stream_context_create', $kernel);
        $this->assertStringContainsString('StreamContextJitHelper', $kernel);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $kernel);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $kernel);
        $this->assertStringNotContainsString('implementMergeOptions', $kernel);
        $orchestrator = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StreamContextRuntime.php');
        $this->assertStringContainsString('JitStreamContextKernel::implement', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
    }

    /**
     * NestedJIT compile of StreamContextJitHelper still fails under MODE_AOT standalone on master
     * (HashTable::iterateKeyed seen as object::iteratekeyed). Guard the quarantine wiring only —
     * full NestedJIT smoke remains blocked until that helper typing gap is fixed.
     *
     * @group aot-lint
     */
    public function testOrchestratorDelegatesImplementToKernel(): void
    {
        $orchestrator = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StreamContextRuntime.php');
        $this->assertStringContainsString('JitStreamContextKernel::implement', $orchestrator);
        $this->assertTrue(method_exists(StreamContextRuntime::class, 'implement'));
        $this->assertTrue(method_exists(\PHPCompiler\ext\standard\JitStreamContextKernel::class, 'implement'));
        $this->assertTrue(method_exists(\PHPCompiler\ext\standard\JitStreamContextKernel::class, 'helperFunction'));
    }
}
