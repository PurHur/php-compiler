<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StreamContextRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5392 / #9340: stream_context LLVM routes through StreamContextJitHelper PHP.
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
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StreamContextRuntime.php');
        $this->assertStringContainsString('__phpc_stream_context_create', $runtime);
        $this->assertStringContainsString('StreamContextJitHelper', $runtime);
        $this->assertStringNotContainsString('implementMergeOptions', $runtime);
    }

    /**
     * @group aot-lint
     */
    public function testImplementDefinesStreamContextForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StreamContextRuntime::implement($ctx);

        $fn = $ctx->lookupFunction('__phpc_stream_context_create');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }
}
