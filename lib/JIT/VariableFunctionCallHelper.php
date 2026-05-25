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
 * LLVM lowering for dynamic variable function calls (issue #1997, phase 2 of #56).
 */
final class VariableFunctionCallHelper
{
    private static int $blockSeq = 0;

    /**
     * @return list<string> lowercase names that may flow into a dynamic $fn() callee.
     */
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
        foreach ($context->userFunctionNames() as $lc) {
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

        if (1 === \count($candidates)) {
            return self::dispatchSingleCandidate($context, $nameStr, array_key_first($candidates), reset($candidates), ...$args);
        }

        $nativeLong = self::candidatesReturnNativeLong($context, $candidates);
        $tag = 'vf'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'var_fn_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'var_fn_undef_'.$tag);
        if ($nativeLong) {
            $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int64'));
            $zero = $context->getTypeFromString('int64')->constInt(0, false);
        } else {
            $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
            $zero = $context->getTypeFromString('__value__*')->constNull();
        }

        $n = \count($candidates);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'var_fn_check_'.$tag.'_'.$i);
        }

        $i = 0;
        foreach ($candidates as $fnName => $proxy) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $literalStr = $context->builder->load($context->constantStringFromString($fnName));
            $isMatch = JitStringCompare::identical($context, $nameStr, $literalStr);
            $onMatch = BasicBlockHelper::append($context, 'var_fn_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isMatch, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $raw = $proxy->call($context, ...$args);
            if ($nativeLong) {
                $context->builder->store($raw, $resultSlot);
            } else {
                $boxed = self::boxCallResult($context, $proxy, $fnName, $raw);
                $context->builder->store($boxed, $resultSlot);
            }
            $context->builder->branch($merge);
            ++$i;
        }

        $context->builder->positionAtEnd($undef);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->store($zero, $resultSlot);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    private static function dispatchSingleCandidate(
        Context $context,
        Value $nameStr,
        string $fnName,
        Call $proxy,
        Variable ...$args
    ): Value {
        $tag = 'vf1'.(string) ++self::$blockSeq;
        $literalStr = $context->builder->load($context->constantStringFromString($fnName));
        $isMatch = JitStringCompare::identical($context, $nameStr, $literalStr);
        $onMatch = BasicBlockHelper::append($context, 'var_fn_one_match_'.$tag);
        $onMiss = BasicBlockHelper::append($context, 'var_fn_one_miss_'.$tag);
        $merge = BasicBlockHelper::append($context, 'var_fn_one_merge_'.$tag);
        $nativeLong = self::candidatesReturnNativeLong($context, [$fnName => $proxy]);
        if ($nativeLong) {
            $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int64'));
        } else {
            $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        }
        $context->builder->branchIf($isMatch, $onMatch, $onMiss);
        $context->builder->positionAtEnd($onMatch);
        $raw = $proxy->call($context, ...$args);
        if ($nativeLong) {
            $context->builder->store($raw, $resultSlot);
        } else {
            $context->builder->store(self::boxCallResult($context, $proxy, $fnName, $raw), $resultSlot);
        }
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($onMiss);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    /**
     * @param array<string, Call> $candidates
     */
    private static function candidatesReturnNativeLong(Context $context, array $candidates): bool
    {
        foreach ($candidates as $fnName => $proxy) {
            $lc = strtolower($fnName);
            $retTy = $context->functionReturnType[$lc] ?? null;
            if ('int64' === $retTy || 'strlen' === $lc) {
                continue;
            }

            return false;
        }

        return [] !== $candidates;
    }

    private static function boxCallResult(Context $context, Call $proxy, string $fnName, Value $raw): Value
    {
        $slot = JitValueBox::alloc($context);
        $rawTy = $context->getStringFromType($raw->typeOf());
        if ('int64' === $rawTy) {
            JitValueBox::writeLong($context, $slot, $raw);

            return JitValueBox::pointer($context, $slot);
        }
        $retTy = $context->functionReturnType[strtolower($fnName)] ?? '__value__';
        if ('int64' === $retTy) {
            JitValueBox::writeLong($context, $slot, $raw);

            return JitValueBox::pointer($context, $slot);
        }
        if ('double' === $retTy) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                JitValueBox::pointer($context, $slot),
                $raw
            );

            return JitValueBox::pointer($context, $slot);
        }
        if ('bool' === $retTy) {
            JitValueBox::writeBool($context, $slot, $raw);

            return JitValueBox::pointer($context, $slot);
        }
        $rawTy = $context->getStringFromType($raw->typeOf());
        if ('__value__*' === $rawTy || '__value__' === $rawTy) {
            JitValueBox::copyFromPointer(
                $context,
                $slot,
                JitValueBox::normalizeValuePtr($context, $raw)
            );

            return JitValueBox::pointer($context, $slot);
        }
        if ('__string__*' === $rawTy) {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                JitValueBox::pointer($context, $slot),
                $raw
            );

            return JitValueBox::pointer($context, $slot);
        }
        if ('int1' === $rawTy) {
            JitValueBox::writeBool($context, $slot, $raw);

            return JitValueBox::pointer($context, $slot);
        }

        JitValueBox::copyFromPointer(
            $context,
            $slot,
            JitValueBox::normalizeValuePtr($context, $raw)
        );

        return JitValueBox::pointer($context, $slot);
    }
}
