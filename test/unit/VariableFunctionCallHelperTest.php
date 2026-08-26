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
        $hints = array_merge(
            VariableFunctionCallHelper::hintedCalleeNames($block, null),
            VariableFunctionCallHelper::coalesceBranchLiteralHints($block),
            VariableFunctionCallHelper::funDefNamesInCompilationUnit($block)
        );
        $this->assertContains('strlen', $hints);
        $this->assertContains('myfn', $hints);
        $this->assertLessThan(
            16,
            count(array_unique($hints)),
            'dynamic $fn() must not lower a dispatch chain over every registered native builtin'
        );
    }

    public function testForeachArrayLiteralCalleeHintsReachDispatch(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
foreach (['strlen', 'strrev'] as $fn) {
    echo $fn('hi');
}
PHP;
        $block = $runtime->parseAndCompile($code, 'vf_foreach_hints.php');
        // FUNCCALL_INIT name is local $fn (ASSIGN dest); walk CFG — call sits in the loop body.
        $nameSlot = null;
        $queue = [$block];
        $seen = [];
        while ([] !== $queue) {
            $current = array_shift($queue);
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($current->opCodes as $op) {
                if (OpCode::TYPE_FUNCCALL_INIT !== $op->type || null === $op->arg1) {
                    continue;
                }
                $nameOp = $current->getOperand($op->arg1);
                if ($nameOp instanceof \PHPCfg\Operand\Literal) {
                    continue;
                }
                $nameSlot = $current->slotForOperand($nameOp);
                break 2;
            }
            foreach ($current->opCodes as $op) {
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
        }
        $this->assertNotNull($nameSlot, 'expected dynamic FUNCCALL_INIT name slot');
        $hints = VariableFunctionCallHelper::hintedCalleeNames($block, $nameSlot);
        $this->assertContains('strlen', $hints);
        $this->assertContains('strrev', $hints);
    }
}
