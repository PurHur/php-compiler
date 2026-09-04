<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPCompiler\JIT\SpineChunkRuntimeMethodDemote;
use PHPUnit\Framework\TestCase;

/**
 * SPINE_CHUNK Runtime method demote capacity gate (#36387).
 *
 * @group aot-lint
 */
final class SpineChunkRuntimeMethodDemoteTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK);
        unset($_ENV[ExternalMethodBind::ENV_SPINE_CHUNK], $_SERVER[ExternalMethodBind::ENV_SPINE_CHUNK]);
        parent::tearDown();
    }

    public function testShouldDemoteHubCapacityClassesUnderSpineChunk(): void
    {
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Runtime'));
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Runtime'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\runtime'));
        // Entire PHPCompiler\VM\* namespace — NestedJIT traps as hub singletons / packed hubs (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\Variable'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\HashTable'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\TypeCheck'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\Context'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\ErrorReporter'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\ClassEntry'));
        // AOT\* NestedJIT OOM / try-catch null insert / computed include under SPINE_CHUNK (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\AOT\\HelperRuntimeCache'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\AOT\\ProjectGraph'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\AOT\\AotEmitFastExit'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\aot\\composervendormap'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CompilerVersion'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\JIT\\Variable'));
    }

    public function testDemoteMethodBlockLeavesOnlyReturnVoid(): void
    {
        $block = new Block(null);
        $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, 0));
        $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, 1));
        $block->blocks[] = new Block(null);
        SpineChunkRuntimeMethodDemote::demoteMethodBlock($block, 'initparsepipeline');
        $this->assertCount(1, $block->opCodes);
        $this->assertSame(OpCode::TYPE_RETURN_VOID, $block->opCodes[0]->type);
        $this->assertSame([], $block->blocks);
    }
}
