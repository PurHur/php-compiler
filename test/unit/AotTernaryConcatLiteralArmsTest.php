<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Issue #35095 — AOT must stack-phi ?: literal arms when merge CONCATs the result
 * (chained lit.lit.(?:) and `$b = ?:; echo lit.$b`), not only rewrite ECHO via #18784.
 */
final class AotTernaryConcatLiteralArmsTest extends TestCase
{
    public function testMergeConcatReadsTernaryResultForChainedLiteralConcat(): void
    {
        $code = <<<'PHP'
<?php
echo "ok" . "|" . (json_last_error() === JSON_ERROR_DEPTH ? "depth" : "other");
PHP;
        $runtime = new Runtime();
        $compiled = $runtime->compile($runtime->parse($code, 'probe.php'));
        [$jumpIf, $merge] = $this->findJumpIfAndMerge($compiled);
        self::assertNotNull($jumpIf);
        self::assertNotNull($merge);
        $resultSlot = $this->mergeTernaryResultSlotViaReflection($merge, $jumpIf);
        self::assertNotNull($resultSlot);
        self::assertTrue(
            $this->mergeConcatReadsViaReflection($merge, $resultSlot),
            'chained lit.lit.(?:) merge must be detected as CONCAT-reads-ternary (#35095)'
        );
    }

    public function testMergeConcatReadsTernaryResultForAssignThenConcat(): void
    {
        $code = <<<'PHP'
<?php
$x = strlen("abc");
$b = $x === 3 ? "yes" : "no";
echo "ok|" . $b;
PHP;
        $runtime = new Runtime();
        $compiled = $runtime->compile($runtime->parse($code, 'probe.php'));
        [$jumpIf, $merge] = $this->findJumpIfAndMerge($compiled);
        self::assertNotNull($jumpIf);
        self::assertNotNull($merge);
        $resultSlot = $this->mergeTernaryResultSlotViaReflection($merge, $jumpIf);
        self::assertNotNull($resultSlot);
        self::assertTrue(
            $this->mergeConcatReadsViaReflection($merge, $resultSlot),
            '$b = ?:; echo lit.$b merge must follow ASSIGN alias into CONCAT (#35095)'
        );
    }

    /** @return array{0: ?Block, 1: ?Block} jump-if block and its merge */
    private function findJumpIfAndMerge(Block $root): array
    {
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_JUMPIF === $op->type && $op->block1 instanceof Block) {
                    $merge = $this->branchJumpMergeViaOpcodes($op->block1);
                    return [$block, $merge];
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $stack[] = $sub;
                    }
                }
            }
        }

        return [null, null];
    }

    private function branchJumpMergeViaOpcodes(Block $branch): ?Block
    {
        foreach ($branch->opCodes as $op) {
            if (OpCode::TYPE_JUMP === $op->type && $op->block1 instanceof Block) {
                return $op->block1;
            }
        }

        return null;
    }

    private function mergeTernaryResultSlotViaReflection(Block $merge, Block $jumpIf): ?int
    {
        // Prefer CONCAT non-literal side / ASSIGN src that arms write — mirror JIT helper
        // without constructing a full JIT (unit gate stays host-light).
        $ifBlock = null;
        $elseBlock = null;
        foreach ($jumpIf->opCodes as $op) {
            if (OpCode::TYPE_JUMPIF === $op->type) {
                $ifBlock = $op->block1;
                $elseBlock = $op->block2;
                break;
            }
        }
        foreach ($merge->opCodes as $mergeOp) {
            if (OpCode::TYPE_ASSIGN === $mergeOp->type && null !== $mergeOp->arg2 && null !== $mergeOp->arg3) {
                $src = (int) $mergeOp->arg3;
                if ($this->armsAssignInto($ifBlock, $elseBlock, $src)) {
                    return $src;
                }
            }
            if (OpCode::TYPE_CONCAT === $mergeOp->type && null !== $mergeOp->arg2 && null !== $mergeOp->arg3) {
                $left = (int) $mergeOp->arg2;
                $right = (int) $mergeOp->arg3;
                $leftLit = $merge->getOperand($left) instanceof \PHPCfg\Operand\Literal;
                $rightLit = $merge->getOperand($right) instanceof \PHPCfg\Operand\Literal;
                if ($leftLit && !$rightLit) {
                    return $right;
                }
                if ($rightLit && !$leftLit) {
                    return $left;
                }
                foreach ([$right, $left] as $cand) {
                    if ($this->armsAssignInto($ifBlock, $elseBlock, $cand)) {
                        return $cand;
                    }
                }

                return $left;
            }
        }

        return null;
    }

    private function mergeConcatReadsViaReflection(Block $merge, int $resultSlot): bool
    {
        $aliases = [$resultSlot => true];
        foreach ($merge->opCodes as $mergeOp) {
            if (
                OpCode::TYPE_ASSIGN === $mergeOp->type
                && null !== $mergeOp->arg2
                && null !== $mergeOp->arg3
            ) {
                $src = (int) $mergeOp->arg3;
                $dest = (int) $mergeOp->arg2;
                if (isset($aliases[$src])) {
                    $aliases[$dest] = true;
                }
            }
            if (
                OpCode::TYPE_CONCAT === $mergeOp->type
                && null !== $mergeOp->arg2
                && null !== $mergeOp->arg3
                && (
                    isset($aliases[(int) $mergeOp->arg2])
                    || isset($aliases[(int) $mergeOp->arg3])
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function armsAssignInto(?Block $ifBlock, ?Block $elseBlock, int $slot): bool
    {
        foreach ([$ifBlock, $elseBlock] as $branch) {
            if (null === $branch) {
                continue;
            }
            foreach ($branch->opCodes as $branchOp) {
                if (OpCode::TYPE_ASSIGN === $branchOp->type && (int) $branchOp->arg2 === $slot) {
                    return true;
                }
            }
        }

        return false;
    }
}
