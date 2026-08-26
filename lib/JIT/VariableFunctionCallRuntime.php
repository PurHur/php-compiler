<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;
use PHPLLVM\Value;

/**
 * JIT/AOT runtime dispatch for dynamic $fn() (#10135, #24902, #35075).
 *
 * Matches the runtime name with {@see JitStringCompare::identical} per compile-time
 * candidate. The prior NestedJIT index-table helper always selected the first ksort'd
 * callee for every name, so multi-hint $fn() was wrong (#35075).
 *
 * php-src: Zend/zend_execute.c — ZEND_INIT_FCALL_BY_NAME / variable calls
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

        $n = \count($candidates);
        if (0 === $n) {
            $context->builder->branch($undef);
        } else {
            $checkBlocks = [];
            for ($i = 0; $i < $n; ++$i) {
                $checkBlocks[$i] = BasicBlockHelper::append($context, 'var_fn_name_check_'.$tag.'_'.$i);
            }
            $context->builder->branch($checkBlocks[0]);

            $i = 0;
            foreach ($candidates as $fnName => $proxy) {
                $context->builder->positionAtEnd($checkBlocks[$i]);
                $lit = $context->builder->load($context->constantStringFromString($fnName));
                $isCase = JitStringCompare::identical($context, $nameStr, $lit);
                $onMatch = BasicBlockHelper::append($context, 'var_fn_name_match_'.$tag.'_'.$i);
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
        if ('int64' === $rawTy) {
            JitValueBox::writeLong($context, $slot, $raw);

            return JitValueBox::pointer($context, $slot);
        }
        // Native double before retTy lookup — mixed abs/round dispatch leaves retTy unset (#35075).
        if ('double' === $rawTy) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                JitValueBox::pointer($context, $slot),
                $raw
            );

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
