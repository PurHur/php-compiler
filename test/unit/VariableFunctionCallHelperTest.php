<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\JIT\VariableFunctionCallHelper;

final class VariableFunctionCallHelperTest extends TestCase
{
    public function testDispatchCandidatesUsesHintsNotAllBuiltins(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function myfn(): int {
    return 1;
}
$name = $_GET['op'] ?? 'strlen';
echo $name('x');
PHP;
        $block = $runtime->parseAndCompile($code, 'vf_hints.php');
        $ctx = $runtime->loadJit()->context;
        $hints = array_merge(
            VariableFunctionCallHelper::hintedCalleeNames($block, null),
            VariableFunctionCallHelper::coalesceBranchLiteralHints($block),
            VariableFunctionCallHelper::funDefNamesInCompilationUnit($block)
        );
        $candidates = VariableFunctionCallHelper::dispatchCandidates($ctx, $hints);
        $this->assertArrayHasKey('strlen', $candidates);
        $this->assertLessThan(
            16,
            count($candidates),
            'dynamic $fn() must not lower a dispatch chain over every registered native builtin'
        );
        $this->assertContains('myfn', $hints);
    }

    /** foreach (['strlen', …] as $fn) binds via ITER_VALUE, not TYPE_ASSIGN of a literal (#35075). */
    public function testHintedCalleeNamesFromForeachArrayLiteral(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
foreach (['strlen', 'strtoupper'] as $fn) {
    echo $fn('hi');
}
PHP;
        $block = $runtime->parseAndCompile($code, 'vf_foreach_hints.php');
        $fnSlot = null;
        $seen = [];
        $queue = [$block];
        while ([] !== $queue) {
            $current = array_shift($queue);
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($current->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type && null !== $op->arg1) {
                    $fnSlot = $op->arg1;
                    break 2;
                }
                foreach ([$op->block1 ?? null, $op->block2 ?? null, $op->block3 ?? null] as $child) {
                    if ($child instanceof Block) {
                        $queue[] = $child;
                    }
                }
            }
            foreach ($current->blocks as $child) {
                if ($child instanceof Block) {
                    $queue[] = $child;
                }
            }
            foreach ($current->parents as $parent) {
                if ($parent instanceof Block) {
                    $queue[] = $parent;
                }
            }
        }
        $this->assertNotNull($fnSlot, 'expected FUNCCALL_INIT name slot');
        $hints = VariableFunctionCallHelper::hintedCalleeNames($block, $fnSlot);
        $this->assertContains('strlen', $hints);
        $this->assertContains('strtoupper', $hints);
    }
}
