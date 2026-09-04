<?php

namespace PHPCompiler\Compiler\Concern;

use PHPCompiler\Block;
use PHPCompiler\Config;
use PHPCompiler\OpCode;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Op;
use PHPCfg\Operand;
use SplObjectStorage;

/**
 * CFG producer index, rematerialization, and inline call-arg producer CFG children (#36387).
 *
 * Extracted from {@see \PHPCompiler\Compiler} so the hub keeps shrinking toward
 * host-CFG split-TU emit under SPINE_CHUNK (gen-0 <30m).
 *
 * Covers O(1) producer-expr indexing over seen CFG block trees (#16077), rematerializing
 * hoisted producers into opcode lists, and defaultBlock children compilation so inline
 * Array_ producers stay visible to TYPE_ARG_SEND wiring (#22390 / #15848).
 *
 * Note: no declare(strict_types=1) — parent Compiler.php is weak-types.
 */
trait CfgProducerIndexAndRematerialize
{
    /**
     * Map hoisted inline producer slot to cfg child index — skips Expr_Param and other non-producers (#17259).
     *
     * @param list<Op> $cfgChildren
     */
    private function inlineCallArgProducerCfgChildIndex(
        int $callIndex,
        int $producerSlotIndex,
        int $producerCount,
        array $cfgChildren
    ): ?int {
        if ($producerSlotIndex < 0 || $producerSlotIndex >= $producerCount || $callIndex < 1) {
            return null;
        }
        $inlineIndices = [];
        for ($i = $callIndex - 1; $i >= 0; --$i) {
            $child = $cfgChildren[$i] ?? null;
            if (!$child instanceof Op\Expr || !$this->isInlineExprCallArgProducer($child)) {
                if ([] !== $inlineIndices) {
                    break;
                }
                continue;
            }
            $inlineIndices[] = $i;
            if (\count($inlineIndices) >= $producerCount) {
                break;
            }
        }
        if (\count($inlineIndices) < $producerCount) {
            $fallback = $callIndex - $producerCount + $producerSlotIndex;
            if ($fallback >= 0 && $fallback < $callIndex) {
                return $fallback;
            }

            return null;
        }
        $chronological = array_reverse(\array_slice($inlineIndices, 0, $producerCount));

        return $chronological[$producerSlotIndex] ?? null;
    }

    /**
     * Compile a php-cfg defaultBlock (param/static/property `new`/array inits) so inline
     * Array_ producers are visible to TYPE_ARG_SEND wiring (#22390, #8561, #15848).
     */
    private function compileDefaultBlockChildrenWithProducerCfg(CfgBlock $defaultBlock, Block $block): void
    {
        $savedProducerCfg = $this->rematerializeInlineProducerCfgBlock;
        $this->rematerializeInlineProducerCfgBlock = $defaultBlock;
        try {
            $this->compileOps($defaultBlock->children, $block);
        } finally {
            $this->rematerializeInlineProducerCfgBlock = $savedProducerCfg;
        }
    }

    /**
     * CFG stmt children for hoisted inline call-arg producer lookup during rematerialization (#15848).
     *
     * @return list<Op>
     */
    private function inlineCallArgProducerCfgChildren(Block $block): array
    {
        $cfg = $this->rematerializeInlineProducerCfgBlock ?? $block->orig;
        if (null === $cfg) {
            return [];
        }

        return $cfg->children;
    }

    private function findCfgBlockContainingExpr(Op $expr): ?CfgBlock
    {
        if (null === $this->seen) {
            return null;
        }
        foreach ($this->seen as $cfgBlock) {
            if (!$cfgBlock instanceof CfgBlock) {
                continue;
            }
            $seen = [];
            $found = $this->findCfgBlockContainingExprInTree($cfgBlock, $expr, $seen);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    private function findCfgBlockContainingExprInTree(
        CfgBlock $cfgBlock,
        Op $expr,
        array &$seen = []
    ): ?CfgBlock {
        $id = spl_object_id($cfgBlock);
        if (isset($seen[$id])) {
            return null;
        }
        $seen[$id] = true;
        foreach ($cfgBlock->children as $child) {
            if ($child === $expr) {
                return $cfgBlock;
            }
        }
        foreach ($cfgBlock->children as $child) {
            if ($child instanceof CfgBlock) {
                $found = $this->findCfgBlockContainingExprInTree($child, $expr, $seen);
                if (null !== $found) {
                    return $found;
                }
            }
            if ($child instanceof Op\Stmt\JumpIf) {
                foreach ([$child->if ?? null, $child->else ?? null] as $branch) {
                    if ($branch instanceof CfgBlock) {
                        $found = $this->findCfgBlockContainingExprInTree($branch, $expr, $seen);
                        if (null !== $found) {
                            return $found;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function findCfgProducerExprForOperand(Operand $operand): ?Op\Expr
    {
        if (null === $this->seen) {
            return null;
        }
        if ('1' !== Config::getenv('PHP_COMPILER_PRODUCER_INDEX_LEGACY')) {
            // O(1) exact lookup instead of re-walking every seen block tree per
            // call — the walk was the top lint/compile hotspot on 30k-line files
            // (#16077). Index misses (root-match producers, blocks mutated after
            // indexing) fall through to the legacy scan below, whose non-exact
            // arm still matches exact producers first.
            $this->syncCfgProducerExprIndex();
            if ($this->cfgProducerExprIndex->contains($operand)) {
                return $this->cfgProducerExprIndex[$operand];
            }
            // No exact producer indexed. The root-match arm below can only hit
            // when some indexed expr shares the operand's cfg var root; when no
            // such root exists anywhere (the overwhelmingly common case), the
            // legacy scan is a guaranteed null — skip it.
            $missRoot = Block::cfgVarRoot($operand);
            if (null === $missRoot
                || !isset($this->cfgProducerRootsWithCandidates[spl_object_id($missRoot)])) {
                return null;
            }
        }
        $returnRoot = Block::cfgVarRoot($operand);
        $rootMatch = null;
        foreach ($this->seen as $cfgBlock) {
            if (!$cfgBlock instanceof CfgBlock) {
                continue;
            }
            $seen = [];
            $producer = $this->findCfgProducerInBlockTree($cfgBlock, $operand, $returnRoot, $seen, true);
            if (null !== $producer) {
                return $producer;
            }
            $seen = [];
            $candidate = $this->findCfgProducerInBlockTree($cfgBlock, $operand, $returnRoot, $seen, false);
            if (null !== $candidate) {
                $rootMatch = $candidate;
            }
        }

        return $rootMatch;
    }

    /**
     * Bring the producer index up to date with $this->seen: index newly seen
     * blocks, re-index blocks whose child list grew or shrank since indexing.
     */
    private function cfgProducerIndexFingerprint(): int
    {
        if (null === $this->seen) {
            return 0;
        }
        $fp = $this->seen->count();
        foreach ($this->seen as $cfgBlock) {
            if ($cfgBlock instanceof CfgBlock) {
                $fp = (int) (($fp * 31) + \count($cfgBlock->children));
            }
        }

        return $fp;
    }

    private function syncCfgProducerExprIndex(): void
    {
        if ($this->cfgProducerIndexSeenSource !== $this->seen || null === $this->cfgProducerExprIndex) {
            $this->cfgProducerExprIndex = new SplObjectStorage();
            $this->cfgProducerIndexedBlocks = new SplObjectStorage();
            $this->cfgProducerRootsWithCandidates = [];
            $this->cfgProducerIndexSeenSource = $this->seen;
            $this->cfgProducerIndexLastSyncFingerprint = -1;
        }
        $fp = $this->cfgProducerIndexFingerprint();
        if ($fp === $this->cfgProducerIndexLastSyncFingerprint) {
            return;
        }
        $this->cfgProducerIndexLastSyncFingerprint = $fp;
        foreach ($this->seen as $cfgBlock) {
            if ($cfgBlock instanceof CfgBlock) {
                $this->indexCfgProducerBlockTree($cfgBlock);
            }
        }
    }

    private function indexCfgProducerBlockTree(CfgBlock $cfgBlock): void
    {
        $childCount = \count($cfgBlock->children);
        $prevCount = $this->cfgProducerIndexedBlocks->contains($cfgBlock)
            ? $this->cfgProducerIndexedBlocks[$cfgBlock]
            : null;
        if ($prevCount === $childCount) {
            return;
        }
        if (null !== $prevCount && $prevCount > $childCount) {
            // Block shrunk — rebuild this subtree in the index (#36224).
            $this->reindexCfgProducerBlockTreeFromScratch($cfgBlock);

            return;
        }
        $startIndex = null !== $prevCount ? $prevCount : 0;
        $this->cfgProducerIndexedBlocks[$cfgBlock] = $childCount;
        for ($i = $startIndex; $i < $childCount; ++$i) {
            $this->indexCfgProducerTreeNode($cfgBlock->children[$i]);
        }
    }

    /**
     * @param Op|CfgBlock $node
     */
    private function indexCfgProducerTreeNode($node): void
    {
        if ($node instanceof Op\Expr) {
            $result = $node->result;
            if ($result instanceof Operand) {
                if (!$this->cfgProducerExprIndex->contains($result)) {
                    $this->cfgProducerExprIndex[$result] = $node;
                }
                $resultRoot = Block::cfgVarRoot($result);
                if (null !== $resultRoot) {
                    $this->cfgProducerRootsWithCandidates[spl_object_id($resultRoot)] = true;
                }
            }

            return;
        }
        if ($node instanceof CfgBlock) {
            $this->indexCfgProducerBlockTree($node);

            return;
        }
        if ($node instanceof Op\Stmt\JumpIf) {
            foreach ([$node->if ?? null, $node->else ?? null] as $branch) {
                if ($branch instanceof CfgBlock) {
                    $this->indexCfgProducerBlockTree($branch);
                }
            }
        }
    }

    private function reindexCfgProducerBlockTreeFromScratch(CfgBlock $cfgBlock): void
    {
        $this->cfgProducerIndexedBlocks[$cfgBlock] = \count($cfgBlock->children);
        foreach ($cfgBlock->children as $child) {
            $this->indexCfgProducerTreeNode($child);
        }
    }

    private function findCfgProducerInBlockTree(
        CfgBlock $cfgBlock,
        Operand $operand,
        ?Operand $returnRoot,
        array &$seen = [],
        bool $exactOnly = false
    ): ?Op\Expr {
        $id = spl_object_id($cfgBlock);
        if (isset($seen[$id])) {
            return null;
        }
        $seen[$id] = true;
        foreach ($cfgBlock->children as $child) {
            if ($child instanceof Op\Expr) {
                $result = $child->result;
                if (!$result instanceof Operand) {
                    continue;
                }
                if ($result === $operand) {
                    return $child;
                }
                if (!$exactOnly && null !== $returnRoot && Block::cfgVarRoot($result) === $returnRoot) {
                    return $child;
                }
            }
            if ($child instanceof CfgBlock) {
                $found = $this->findCfgProducerInBlockTree($child, $operand, $returnRoot, $seen, $exactOnly);
                if (null !== $found) {
                    return $found;
                }
            }
            if ($child instanceof Op\Stmt\JumpIf) {
                foreach ([$child->if ?? null, $child->else ?? null] as $branch) {
                    if ($branch instanceof CfgBlock) {
                        $found = $this->findCfgProducerInBlockTree($branch, $operand, $returnRoot, $seen, $exactOnly);
                        if (null !== $found) {
                            return $found;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function cfgBlockContainsOp(?CfgBlock $cfgBlock, Op $needle): bool
    {
        if (null === $cfgBlock) {
            return false;
        }
        foreach ($cfgBlock->children as $child) {
            if ($child === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<OpCode>
     */
    private function rematerializeCfgProducerExprOps(Op\Expr $producer, Block $block): array
    {
        if ($this->cfgBlockContainsOp($block->orig, $producer)) {
            return [];
        }
        if ($producer instanceof Op\Expr\FuncCall || $producer instanceof Op\Expr\NsFuncCall) {
            $savedProducerCfg = $this->rematerializeInlineProducerCfgBlock;
            $this->rematerializeInlineProducerCfgBlock = $this->findCfgBlockContainingExpr($producer);
            try {
                return $this->compileFuncCall(
                    $this->compileOperand($producer->name, $block, true),
                    $producer->args ?? [],
                    $producer->result,
                    $block,
                    max(0, $producer->getLine()),
                    $producer
                );
            } finally {
                $this->rematerializeInlineProducerCfgBlock = $savedProducerCfg;
            }
        }
        if ($producer instanceof Op\Expr\BinaryOp) {
            $ops = [];
            foreach (['left', 'right'] as $side) {
                $operand = $producer->$side;
                if (!$operand instanceof Operand) {
                    continue;
                }
                $nested = $this->findCfgProducerExprForOperand($operand);
                if ($nested instanceof Op\Expr) {
                    $ops = array_merge($ops, $this->rematerializeCfgProducerExprOps($nested, $block));
                }
            }

            return array_merge($ops, $this->compileExpr($producer, $block));
        }
        if ($producer instanceof Op\Expr\Cast) {
            $nested = $this->findCfgProducerExprForOperand($producer->expr);
            $ops = [];
            if ($nested instanceof Op\Expr) {
                $ops = $this->rematerializeCfgProducerExprOps($nested, $block);
            }

            return array_merge($ops, $this->compileExpr($producer, $block));
        }

        return $this->compileExpr($producer, $block);
    }
}
