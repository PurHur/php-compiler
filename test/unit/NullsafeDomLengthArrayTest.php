<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * DOM live-collection length before ?-> in the same array literal (#28555).
 * FUNCCALL dead-temp release must keep the length slot live across TYPE_NULLSAFE merge.
 */
final class NullsafeDomLengthArrayTest extends \PHPUnit\Framework\TestCase
{
    public function testVmMatchesZend(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompileFile(
            __DIR__ . '/../repro/nullsafe_dom_length_array.php'
        ));
        $this->assertSame(
            "attrs=[2,\"1\"]\nlist=[1,\"a\"]\nchildNodes=[1,\"a\"]\n",
            ob_get_clean()
        );
    }

    public function testNullsafeMergeIsControlFlowBranchTarget(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompileFile(
            __DIR__ . '/../repro/nullsafe_dom_length_array.php'
        );
        $nullsafe = null;
        $lengthSlot = null;
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
                if (OpCode::TYPE_NULLSAFE === $op->type) {
                    $nullsafe = $op;
                }
                if (OpCode::TYPE_INIT_ARRAY === $op->type && null === $lengthSlot) {
                    $lengthSlot = $op->arg2;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $next) {
                    if ($next instanceof Block) {
                        $queue[] = $next;
                    }
                }
            }
        }
        $this->assertNotNull($nullsafe);
        $this->assertNotNull($nullsafe->block3);
        $this->assertNotNull($lengthSlot);
        $this->assertTrue(
            $block->scopeSlotReadInJumpTargets((int) $lengthSlot),
            'length slot must stay live across nullsafe merge for INIT_ARRAY'
        );
    }
}
