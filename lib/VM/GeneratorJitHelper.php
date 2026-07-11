<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Operand\Literal;
use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;

/**
 * Compile-time generator CFG analysis for JIT/AOT (#10105, php-in-PHP).
 *
 * VM SSOT: {@see GeneratorState} — runtime resume/yield semantics
 * php-src: Zend/zend_generators.c — generator create/resume/close
 */
final class GeneratorJitHelper
{
    public const TARGET_PROPERTY = '__generator_resume';

    /** int64 property holding {@see __generator_state__*} bits (#3115). */
    public const STATE_PROPERTY = '__generator_state';

    public const YIELD_FROM_STRING_ERROR = 'Can use "yield from" only with arrays and Traversables';

    public const YIELD_FROM_TYPE_ERROR = 'Can only use yield from on Traversable|array';

    /** zend_generators.c — foreach by-ref requires generator yields-by-ref (#4599). */
    public const FOREACH_GENERATOR_BYREF_ERROR = 'You can only iterate a generator by-reference if it declared that it yields by-reference';

    public static function yieldFromContainerUserType(Block $block, OpCode $op): ?string
    {
        if (null === $op->arg2) {
            return null;
        }
        $operand = $block->getOperand($op->arg2);
        $userType = $operand->type->userType ?? null;
        if (null !== $userType && '' !== $userType) {
            return $userType;
        }

        return null;
    }

    public static function creatorResumeName(Context $context, string $funcLc): ?string
    {
        $lc = strtolower($funcLc);
        if (isset($context->generatorCreators[$lc])) {
            return $context->generatorCreators[$lc];
        }
        if (preg_match('/^(.+)\\\\([^\\\\]+)$/', $lc, $m)) {
            $short = $m[2];
            if (isset($context->generatorCreators[$short])) {
                return $context->generatorCreators[$short];
            }
        }

        return null;
    }

    public static function isGeneratorVariable(Variable $var): bool
    {
        return null !== $var->generatorStatePtr
            || null !== $var->generatorResumeName
            || $var->isJitGenerator;
    }

    /**
     * @return list<array{kind: string, op: OpCode, block: Block}>
     */
    public static function collectResumePoints(Block $entry): array
    {
        $points = [];
        $visited = new \SplObjectStorage();
        self::walkBlockForResumePoints($entry, $points, $visited);

        return $points;
    }

    /**
     * @param list<array{kind: string, op: OpCode, block: Block}> $points
     */
    private static function walkBlockForResumePoints(
        Block $block,
        array &$points,
        \SplObjectStorage $visited
    ): void {
        if ($visited->contains($block)) {
            return;
        }
        $visited->attach($block);
        foreach ($block->opCodes as $i => $op) {
            if (OpCode::TYPE_YIELD === $op->type) {
                $points[] = ['kind' => 'yield', 'op' => $op, 'block' => $block];
                continue;
            }
            if (OpCode::TYPE_YIELD_FROM === $op->type) {
                $points[] = ['kind' => 'yield_from', 'op' => $op, 'block' => $block];
                continue;
            }
            if (OpCode::TYPE_RETURN === $op->type || OpCode::TYPE_RETURN_VOID === $op->type) {
                return;
            }
            if (OpCode::TYPE_TRY === $op->type) {
                if (null !== $op->block1) {
                    self::walkBlockForResumePoints($op->block1, $points, $visited);
                }
                self::collectCatchResumePoints($block, $i, $points, $visited);

                continue;
            }
            if (
                OpCode::TYPE_CATCH === $op->type
                || OpCode::TYPE_FINALLY === $op->type
                || OpCode::TYPE_THROW === $op->type
                || OpCode::TYPE_RETHROW === $op->type
            ) {
                continue;
            }
            if (OpCode::TYPE_JUMP === $op->type && null !== $op->block2) {
                self::walkBlockForResumePoints($op->block2, $points, $visited);

                return;
            }
        }
    }

    /**
     * @param list<array{kind: string, op: OpCode, block: Block}> $points
     */
    private static function collectCatchResumePoints(
        Block $handlerBlock,
        int $afterTryIndex,
        array &$points,
        \SplObjectStorage $visited
    ): void {
        $n = $handlerBlock->nOpCodes;
        for ($j = $afterTryIndex + 1; $j < $n; ++$j) {
            $op = $handlerBlock->opCodes[$j];
            if (OpCode::TYPE_JUMP === $op->type) {
                continue;
            }
            if (OpCode::TYPE_CATCH !== $op->type) {
                break;
            }
            if (null !== $op->block1) {
                self::walkBlockForResumePoints($op->block1, $points, $visited);
            }
        }
    }

    public static function cfgBlockContains(Block $root, Block $needle): bool
    {
        if ($root === $needle) {
            return true;
        }
        $seen = new \SplObjectStorage();
        $stack = [$root];
        while ([] !== $stack) {
            $current = array_pop($stack);
            if (!$current instanceof Block || $seen->contains($current)) {
                continue;
            }
            $seen->attach($current);
            if ($current === $needle) {
                return true;
            }
            foreach ($current->opCodes as $op) {
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $stack[] = $sub;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array{0: Block, 1: OpCode, 2: int}|null
     */
    public static function findTrySetupForYieldBlock(Block $entry, Block $yieldBlock): ?array
    {
        foreach ($entry->opCodes as $i => $op) {
            if (OpCode::TYPE_TRY === $op->type && self::cfgBlockContains($op->block1, $yieldBlock)) {
                return [$entry, $op, $i];
            }
        }

        return null;
    }

    public static function opcodeIndex(Block $block, OpCode $target): int
    {
        foreach ($block->opCodes as $i => $op) {
            if ($op === $target) {
                return $i;
            }
        }

        throw new \LogicException('Generator resume point opcode missing from block');
    }

    /**
     * @param list<array{kind: string, op: OpCode, block: Block}> $points
     */
    public static function resumePrefixStart(array $points, int $pointIndex): int
    {
        if (0 === $pointIndex) {
            return 0;
        }
        $prev = $points[$pointIndex - 1];
        $curr = $points[$pointIndex];
        if ($prev['block'] !== $curr['block']) {
            return 0;
        }

        return self::opcodeIndex($curr['block'], $prev['op']) + 1;
    }

    public static function prefixOpcodesSafeForYieldFromInit(Block $block, int $yieldFromIndex): bool
    {
        return self::prefixSegmentSafeForYieldFromInit($block, 0, $yieldFromIndex);
    }

    /**
     * True when [$start, $end) contains no yield / yield from (safe to compile for container setup).
     */
    public static function prefixSegmentSafeForYieldFromInit(Block $block, int $start, int $end): bool
    {
        for ($i = $start; $i < $end; ++$i) {
            $type = $block->opCodes[$i]->type;
            if (OpCode::TYPE_YIELD === $type || OpCode::TYPE_YIELD_FROM === $type) {
                return false;
            }
        }

        return true;
    }

    /**
     * When yield from delegates to a nested generator call (yield from inner()), return inner resume name.
     */
    public static function resolveYieldFromGeneratorResumeName(
        Block $block,
        OpCode $yieldFromOp,
        Context $context
    ): ?string {
        $yfIdx = null;
        foreach ($block->opCodes as $i => $op) {
            if ($op === $yieldFromOp) {
                $yfIdx = $i;
                break;
            }
        }
        if (null === $yfIdx || !self::prefixOpcodesSafeForYieldFromInit($block, $yfIdx)) {
            return null;
        }
        for ($i = $yfIdx - 1; $i >= 0; --$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_FUNCCALL_INIT !== $op->type) {
                continue;
            }
            $nameOp = $block->getOperand($op->arg1);
            if (!$nameOp instanceof Literal) {
                return null;
            }

            return self::creatorResumeName($context, strtolower($nameOp->value));
        }

        return null;
    }

    public static function llvmInternalName(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? $name;
        if ('main' === $sanitized || '__init__' === $sanitized || '__shutdown__' === $sanitized) {
            return 'php_user_'.$sanitized;
        }

        return $sanitized;
    }
}
