<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCfg\Operand;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\OpCode;
use PHPLLVM\Value;

/**
 * Compile-time hint scanning + candidate resolution for dynamic $fn() (issue #1997).
 *
 * LLVM dispatch: {@see VariableFunctionCallRuntime} via {@see VariableFunctionCallJitHelper} PHP (#10135).
 */
final class VariableFunctionCallHelper
{

    /** Lowercase names that may flow into a dynamic $fn() callee. */
    public static function hintedCalleeNames(Block $block, ?int $nameSlot): array
    {
        $hints = [];
        foreach (self::blocksForHintScan($block) as $scanBlock) {
            if (null !== $nameSlot) {
                foreach ($scanBlock->opCodes as $op) {
                    if (OpCode::TYPE_ASSIGN !== $op->type || $op->arg2 !== $nameSlot) {
                        continue;
                    }
                    $literal = self::literalFromAssignSource($scanBlock, $op->arg3);
                    if (null !== $literal) {
                        $hints[] = $literal;
                    }
                }
            }
            $hints = array_merge($hints, self::assignStringLiteralsInBlock($scanBlock));
        }

        return array_values(array_unique($hints));
    }

    /**
     * User FUNCDEF names in the current TU (not every registered stdlib native).
     *
     * @return list<string>
     */
    public static function funDefNamesInCompilationUnit(Block $block): array
    {
        $names = [];
        foreach (self::blocksForHintScan($block) as $scanBlock) {
            foreach ($scanBlock->opCodes as $op) {
                if (OpCode::TYPE_FUNCDEF !== $op->type || null === $op->arg1) {
                    continue;
                }
                $nameOp = $scanBlock->getOperand($op->arg1);
                if ($nameOp instanceof Operand\Literal) {
                    $literal = strtolower((string) $nameOp->value);
                    if ('' !== $literal && !str_contains($literal, '::')) {
                        $names[] = $literal;
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * String literals assigned on ?? right-hand blocks (e.g. $_GET['op'] ?? 'strlen').
     *
     * @return list<string>
     */
    public static function coalesceBranchLiteralHints(Block $block): array
    {
        $hints = [];
        foreach (self::blocksForHintScan($block) as $scanBlock) {
            foreach ($scanBlock->opCodes as $op) {
                if (OpCode::TYPE_COALESCE !== $op->type || !($op->block2 instanceof Block)) {
                    continue;
                }
                $hints = array_merge($hints, self::assignStringLiteralsInBlock($op->block2));
            }
        }

        return array_values(array_unique($hints));
    }

    /**
     * @return list<Block>
     */
    private static function blocksForHintScan(Block $block): array
    {
        $out = [];
        $seen = [];
        $queue = [$block];
        while ([] !== $queue) {
            $current = array_shift($queue);
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $current;
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
            foreach ($current->parents as $parent) {
                if ($parent instanceof Block) {
                    $queue[] = $parent;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function assignStringLiteralsInBlock(Block $block): array
    {
        $hints = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            $literal = self::literalFromAssignSource($block, $op->arg3);
            if (null !== $literal) {
                $hints[] = $literal;
            }
        }

        return $hints;
    }

    private static function literalFromAssignSource(Block $block, int $sourceSlot): ?string
    {
        if (isset($block->constants[$sourceSlot])) {
            $literal = strtolower($block->constants[$sourceSlot]->toString());

            return ('' !== $literal && !str_contains($literal, '::')) ? $literal : null;
        }
        foreach ($block->scopedOperands() as $op) {
            if ($block->slotForOperand($op) !== $sourceSlot || !($op instanceof Operand\Literal)) {
                continue;
            }
            $literal = strtolower($op->value);

            return ('' !== $literal && !str_contains($literal, '::')) ? $literal : null;
        }

        return null;
    }

    /**
     * @param list<string> $hintedNames
     * @return array<string, Call> lowercase name => proxy
     */
    public static function dispatchCandidates(Context $context, array $hintedNames = []): array
    {
        $out = [];
        foreach ($hintedNames as $hint) {
            $lc = strtolower($hint);
            if (isset($out[$lc])) {
                continue;
            }
            $resolved = self::acceptDispatchProxy($context, $lc, null);
            if (null !== $resolved) {
                $out[$lc] = $resolved;
            }
        }
        ksort($out);

        return $out;
    }

    private static function acceptDispatchProxy(Context $context, string $lc, ?Call $proxy): ?Call
    {
        if (str_contains($lc, '::') || str_starts_with($lc, '__')) {
            return null;
        }
        if (!$context->functionIsRegistered($lc)) {
            return null;
        }
        $proxy ??= $context->resolveFunctionProxy($lc);
        if ($proxy instanceof ExternalMethod) {
            return null;
        }

        return $proxy;
    }

    /**
     * @param list<string> $hintedNames
     */
    public static function dispatch(Context $context, Variable $nameVar, array $hintedNames = [], Variable ...$args): Value
    {
        $nameStr = JitStringArg::lower($context, $nameVar, 'variable function name');
        $candidates = self::dispatchCandidates($context, $hintedNames);
        if ([] === $candidates) {
            $context->builder->call($context->lookupFunction('abort'));

            return JitValueBox::alloc($context);
        }

        return VariableFunctionCallRuntime::dispatch($context, $nameStr, $candidates, ...$args);
    }
}
