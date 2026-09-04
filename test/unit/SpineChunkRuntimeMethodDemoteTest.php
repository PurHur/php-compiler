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

    public function testShouldDemoteOnlyRuntimeUnderSpineChunk(): void
    {
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Runtime'));
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Runtime'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\runtime'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CompilerVersion'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM\\Variable'));
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
