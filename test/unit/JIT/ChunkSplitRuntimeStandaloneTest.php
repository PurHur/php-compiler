<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringChunkSplit;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #30859: JIT/AOT chunk_split routes through ChunkSplitJitHelper + VmChunkSplit bundle.
 *
 * @group aot-lint
 */
final class ChunkSplitRuntimeStandaloneTest extends TestCase
{
    public function testImplementDefinesChunkSplitAbiForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringChunkSplit::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__compiler_chunk_split');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }

    public function testStringChunkSplitRoutesThroughVmChunkSplitBundle(): void
    {
        $runtimeSource = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringChunkSplit.php');
        $this->assertStringContainsString('VmChunkSplit.php', $runtimeSource);
        $this->assertStringContainsString('ensureCompiledBundle', $runtimeSource);
        $this->assertStringContainsString('ChunkSplitJitHelper', $runtimeSource);
    }
}
