<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * RuntimeIndirectInstanceMethodCall must not dispatch HashTable->$add through
 * static OutputRewriteVarsJitHelper::add (#23468 argv rebuild abort).
 */
final class VmPregMatchesAotHashTableAddTest extends TestCase
{
    public function testRuntimeIndirectCandidatesSkipOutputRewriteVarsAdd(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString(
            "phpcompiler\\\\ext\\\\standard\\\\outputrewritevarsjithelper",
            $jit,
            'buildRuntimeInstanceMethodCandidatesByClassId must exclude RewriteVars::add'
        );
        $this->assertStringContainsString('FLAG_STATIC', $jit);
        $nested = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/NestedVmHashTableMethodLlvm.php'
        );
        $this->assertStringContainsString("'add' => Call\\HashTableWriteNested::class", $nested);
    }
}
