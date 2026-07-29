<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT runtime dispatch for dynamic $fn() via VariableFunctionCallJitHelper PHP (#10135, #24902).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer MathModf #22519 / Lcg #22495).
 * Replaces per-candidate JitStringCompare LLVM chains in {@see VariableFunctionCallHelper}.
 */
final class VariableFunctionCallRuntime
{
    private const HELPER_PATH = '/VM/VariableFunctionCallJitHelper.php';

    private const MATCH_INDEX_HELPER = 'PHPCompiler\\VM\\VariableFunctionCallJitHelper::matchCandidateIndex';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MATCH_INDEX_HELPER,
    ];

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
        $candidateNames = \array_keys($candidates);
        $table = \implode("\0", $candidateNames);
        $index = $context->builder->call(
            self::matchHelperFunction($context),
            $nameStr,
            $context->builder->load($context->constantStringFromString($table))
        );

        return self::dispatchByIndex($context, $index, $candidates, ...$args);
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

    private static function matchHelperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::MATCH_INDEX_HELPER, '#24902');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24902'
        );
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
