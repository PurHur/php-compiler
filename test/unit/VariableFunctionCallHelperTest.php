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
        try {
            $ctx = $runtime->loadJit()->context;
        } catch (\Throwable $e) {
            $this->markTestSkipped('loadJit unavailable in this environment: '.$e->getMessage());
        }
        $candidates = VariableFunctionCallHelper::dispatchCandidates($ctx, $hints);
        $this->assertArrayHasKey('strlen', $candidates);
        $this->assertLessThan(
            16,
            count($candidates),
            'dynamic $fn() must not lower a dispatch chain over every registered native builtin'
        );
    }

    public function testForeachArrayLiteralCalleeHintsIncludeInitAndAddElements(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
foreach (['strlen', 'abs', 'round'] as $fn) {
    echo $fn(1);
}
PHP;
        $block = $runtime->parseAndCompile($code, 'vf_foreach_hints.php');
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
                if (OpCode::TYPE_FUNCCALL_INIT === $op->type && null !== $op->arg1) {
                    $nameOp = $current->getOperand($op->arg1);
                    if (!($nameOp instanceof \PHPCfg\Operand\Literal)) {
                        $nameSlot = $op->arg1;
                        break 2;
                    }
                }
                foreach ([$op->block1 ?? null, $op->block2 ?? null, $op->block3 ?? null] as $child) {
                    if ($child instanceof Block) {
                        $queue[] = $child;
                    }
                }
            }
            foreach ($current->blocks as $child) {
                $queue[] = $child;
            }
        }
        $this->assertNotNull($nameSlot, 'expected variable FUNCCALL_INIT name slot');
        $hints = VariableFunctionCallHelper::foreachArrayLiteralCalleeHints($block, $nameSlot);
        $this->assertContains('strlen', $hints);
        $this->assertContains('abs', $hints);
        $this->assertContains('round', $hints);
    }
}
