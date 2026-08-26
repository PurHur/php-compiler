<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT runtime dispatch for dynamic $fn() (#10135, #24902, #35075).
 *
 * Matches the runtime name with {@see JitStringCompare} (not a NUL/RS-delimited
 * helper table — AOT string constants truncate at embedded NUL, which collapsed
 * multi-candidate foreach dispatch to index 0).
 */
final class VariableFunctionCallRuntime
{
    private static int $blockSeq = 0;

    /**
     * @param array<string, Call> $candidates
     */
    public static function dispatch(
        Context $context,
        Value $nameStr,
        array $candidates,
        Variable ...$args
    ): Value {
        $index = self::matchIndexByStrcmp($context, $nameStr, \array_keys($candidates));

        return self::dispatchByIndex($context, $index, $candidates, ...$args);
    }

    /**
     * @param list<string> $candidateNames lowercase names in dispatch order
     */
    private static function matchIndexByStrcmp(Context $context, Value $nameStr, array $candidateNames): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $indexSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($i32->constInt(-1, true), $indexSlot);

        $i = 0;
        foreach ($candidateNames as $candidate) {
            $lit = $context->builder->load($context->constantStringFromString($candidate));
            $cmp = JitStringCompare::strcmp($context, $nameStr, $lit);
            $isMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
            $cur = $context->builder->load($indexSlot);
            $picked = $context->builder->select($isMatch, $i32->constInt($i, false), $cur);
            $context->builder->store($picked, $indexSlot);
            ++$i;
        }

        return $context->builder->load($indexSlot);
    }

    /**
     * @param array<string, Call> $candidates
     */
    private static function dispatchByIndex(
        Context $context,
        Value $index,
        array $candidates,
        Variable ...$args
    ): Value {
        $tag = 'vf'.(string) ++self::$blockSeq;
        $merge = BasicBlockHelper::append($context, 'var_fn_merge_'.$tag);
        $undef = BasicBlockHelper::append($context, 'var_fn_undef_'.$tag);
        $nativeLong = self::candidatesReturnNativeLong($context, $candidates);
        if ($nativeLong) {
            $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int64'));
            $zero = $context->getTypeFromString('int64')->constInt(0, false);
        } else {
            $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
            $zero = $context->getTypeFromString('__value__*')->constNull();
        }

        $i32 = $context->getTypeFromString('int32');
        $indexTy = $context->getStringFromType($index->typeOf());
        if ('int64' === $indexTy) {
            $index = $context->builder->trunc($index, $i32);
        } elseif ('int8' === $indexTy || 'int16' === $indexTy) {
            $index = $context->builder->zExt($index, $i32);
        }
        $minusOne = $i32->constInt(-1, true);
        $isMiss = $context->builder->icmp(Builder::INT_EQ, $index, $minusOne);
        $dispatchEntry = BasicBlockHelper::append($context, 'var_fn_dispatch_'.$tag);
        $context->builder->branchIf($isMiss, $undef, $dispatchEntry);

        $n = \count($candidates);
        $checkBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = 0 === $i
                ? $dispatchEntry
                : BasicBlockHelper::append($context, 'var_fn_idx_check_'.$tag.'_'.$i);
        }

        $i = 0;
        foreach ($candidates as $fnName => $proxy) {
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $isCase = $context->builder->icmp(
                Builder::INT_EQ,
                $index,
                $i32->constInt($i, false)
            );
            $onMatch = BasicBlockHelper::append($context, 'var_fn_idx_match_'.$tag.'_'.$i);
            $onMiss = ($i < $n - 1) ? $checkBlocks[$i + 1] : $undef;
            $context->builder->branchIf($isCase, $onMatch, $onMiss);

            $context->builder->positionAtEnd($onMatch);
            $raw = $proxy->call($context, ...$args);
            if ($nativeLong) {
                $context->builder->store($raw, $resultSlot);
            } else {
                $context->builder->store(self::boxCallResult($context, $fnName, $raw), $resultSlot);
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

    private static function boxCallResult(Context $context, string $fnName, Value $raw): Value
    {
        $slot = JitValueBox::alloc($context);
        $rawTy = $context->getStringFromType($raw->typeOf());
        // Prefer the LLVM return shape: Internal builtins (ceil/floor/round) return
        // native double without setting functionReturnType (#35075).
        if ('int64' === $rawTy) {
            JitValueBox::writeLong($context, $slot, $raw);

            return JitValueBox::pointer($context, $slot);
        }
        if ('double' === $rawTy) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                JitValueBox::pointer($context, $slot),
                $raw
            );

            return JitValueBox::pointer($context, $slot);
        }
        if ('int1' === $rawTy) {
            JitValueBox::writeBool($context, $slot, $raw);

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

        JitValueBox::copyFromPointer(
            $context,
            $slot,
            JitValueBox::normalizeValuePtr($context, $raw)
        );

        return JitValueBox::pointer($context, $slot);
    }
}
