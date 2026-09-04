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
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Block'));
        putenv(ExternalMethodBind::ENV_SPINE_CHUNK.'=1');
        $_ENV[ExternalMethodBind::ENV_SPINE_CHUNK] = '1';
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Runtime'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\runtime'));
        // Top-level Block — NestedJIT hashtable→native-long assign trap under SPINE_CHUNK (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Block'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\block'));
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
        // Compiler\* / Web\* peer TUs — NestedJIT gaps + OOM under SPINE_CHUNK (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Compiler\\InheritanceVariance'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Compiler\\TraitClassConstConflictCheck'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\compiler\\overridevalidator'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Web\\MultipartParser'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\web\\responsecontext'));
        // Ast\* / Cli\* / SourcePreprocessor\* — preg_replace_callback / OOM / string-arg (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Ast\\PipeOperatorDesugar'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\ast\\clonewithdesugar'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Cli\\PhpcBuild'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\cli\\phpcrun'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\SourcePreprocessor\\PropertyHooks'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\sourcepreprocessor\\propertyhooks'));
        // JIT\* peer TUs — isset/goto-resume/segfault under NestedJIT (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\JIT\\Analyzer'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\JIT\\Variable'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\JIT\\Builtin\\CallArgv'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\jit\\arrayfilterllvm'));
        // Top-level VM.php — NestedJIT OOM without demote; emits after (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\VM'));
        // ext\* peer TUs — NestedJIT segfault rc=139 on bcmath class/JIT packs (#36387).
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ext\\bcmath\\NumberAdd'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('phpcompiler\\ext\\bcmath\\jitbcmath'));
        $this->assertTrue(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\ext\\standard\\Strlen'));
        // Compiler / CompilerVersion / JIT stay live — Compiler/JIT need file splits for host CFG.
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Compiler'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\CompilerVersion'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\JIT'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Func\\Internal'));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::shouldDemote('PHPCompiler\\Visitor\\VoidCastResolver'));
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
        $this->assertTrue(SpineChunkRuntimeMethodDemote::isDemotedStub($block));
    }

    public function testIsDemotedStubRejectsNonEmptyBodies(): void
    {
        $block = new Block(null);
        $block->addOpCode(new OpCode(OpCode::TYPE_ECHO, 0));
        $this->assertFalse(SpineChunkRuntimeMethodDemote::isDemotedStub($block));
        $block->opCodes = [];
        $block->addOpCode(new OpCode(OpCode::TYPE_RETURN_VOID));
        $block->blocks[] = new Block(null);
        $this->assertFalse(SpineChunkRuntimeMethodDemote::isDemotedStub($block));
    }
}
