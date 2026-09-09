<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func\Internal as CoreFuncInternal;

/**
 * Discarded pure-call elision for array-key-edge / copy / transform / merge /
 * lookup / construct / combine / range builtins (#36387 / #23483).
 *
 * Extracted from {@see DiscardedPureCallElision} so the 5.5k-line hub is not
 * one monolith TU on the gen-0 spine (split-TU / size-budget ratchet). Shared
 * arg predicates ({@code isTypedArrayArg}, {@code mathArgAllowsDiscardedElision},
 * {@code stringArgAllowsDiscardedElision}) stay on the hub class.
 *
 * Used via {@code use DiscardedPureCallElisionArrayOps;} on
 * {@see DiscardedPureCallElision}.
 *
 * No new C ABI. php-src: ext/standard/array.c (array_key_first / array_column /
 * array_merge / range / …).
 */
trait DiscardedPureCallElisionArrayOps
{
    /**
     * Discarded {@code array_key_first}/{@code array_key_last}/
     * {@code array_is_list} on a typed hashtable / packed array / value-box
     * hashtable — php-src {@code ext/standard/array.c}. Soft-null / non-array
     * stay live ({@code TypeError}); excess argc stays live
     * ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayKeyEdgeNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (
            'array_key_first' !== $name
            && 'array_key_last' !== $name
            && 'array_is_list' !== $name
        ) {
            return false;
        }
        if (1 !== \count($callArgs)) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }

        return self::isTypedArrayArg($callArgs[0]);
    }

    /**
     * Discarded {@code array_keys}/{@code array_values}/{@code array_first}/
     * {@code array_last}/{@code array_reverse}/{@code array_change_key_case}
     * on a typed hashtable / packed array / value-box hashtable — php-src
     * {@code ext/standard/array.c}. Filtered {@code array_keys} (search /
     * strict) stays live. Soft-null / non-array haystacks stay live
     * ({@code TypeError}); soft-null optional flags stay live (deprecate);
     * excess argc stays live ({@code ArgumentCountError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayCopyNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (
            'array_keys' !== $name
            && 'array_values' !== $name
            && 'array_first' !== $name
            && 'array_last' !== $name
            && 'array_reverse' !== $name
            && 'array_change_key_case' !== $name
        ) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        if (!self::isTypedArrayArg($callArgs[0])) {
            return false;
        }

        switch ($name) {
            case 'array_keys':
            case 'array_values':
            case 'array_first':
            case 'array_last':
                // One-arg only — filtered array_keys / excess argc stay live.
                return 1 === \count($callArgs);
            case 'array_reverse':
            case 'array_change_key_case':
                if (isset($callArgs[2])) {
                    return false;
                }
                if (!isset($callArgs[1])) {
                    return true;
                }

                return $callArgs[1] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[1]);
            default:
                return false;
        }
    }

    /**
     * Discarded {@code array_unique}/{@code array_slice}/{@code array_chunk}/
     * {@code array_sum}/{@code array_product} on typed arrays — php-src
     * {@code ext/standard/array.c}. Soft-null / non-array haystacks stay live
     * ({@code TypeError}). {@code array_chunk} requires a compile-time size
     * ≥ 1 ({@code ValueError} otherwise). Soft-null optional flags stay live
     * (deprecate). Excess argc stays live ({@code ArgumentCountError}).
     * {@code array_flip} is not elided (non-int/string values → {@code ValueError}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayTransformNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if (
            'array_unique' !== $name
            && 'array_slice' !== $name
            && 'array_chunk' !== $name
            && 'array_sum' !== $name
            && 'array_product' !== $name
        ) {
            return false;
        }
        if (!isset($callArgs[0]) || !$callArgs[0] instanceof Variable) {
            return false;
        }
        if (!self::isTypedArrayArg($callArgs[0])) {
            return false;
        }

        switch ($name) {
            case 'array_sum':
            case 'array_product':
                return 1 === \count($callArgs);
            case 'array_unique':
                if (isset($callArgs[2])) {
                    return false;
                }
                if (!isset($callArgs[1])) {
                    return true;
                }

                return $callArgs[1] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[1]);
            case 'array_chunk':
                if (!isset($callArgs[1]) || !$callArgs[1] instanceof Variable) {
                    return false;
                }
                if (isset($callArgs[3])) {
                    return false;
                }
                // ValueError when size < 1 — only elide proven positive sizes.
                $size = $callArgs[1]->compileTimeLong;
                if (null === $size || $size < 1) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }

                return $callArgs[2] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[2]);
            case 'array_slice':
                if (!isset($callArgs[1]) || !$callArgs[1] instanceof Variable) {
                    return false;
                }
                if (isset($callArgs[4])) {
                    return false;
                }
                if (!self::mathArgAllowsDiscardedElision($callArgs[1])) {
                    return false;
                }
                if (isset($callArgs[2])) {
                    if (!$callArgs[2] instanceof Variable) {
                        return false;
                    }
                    // null length means "to end" (not a soft-null deprecate).
                    if (
                        !$callArgs[2]->isNullConstant
                        && Variable::TYPE_NULL !== $callArgs[2]->type
                        && !self::mathArgAllowsDiscardedElision($callArgs[2])
                    ) {
                        return false;
                    }
                }
                if (!isset($callArgs[3])) {
                    return true;
                }

                return $callArgs[3] instanceof Variable
                    && self::mathArgAllowsDiscardedElision($callArgs[3]);
            default:
                return false;
        }
    }

    /**
     * Discarded {@code array_merge}/{@code array_merge_recursive}/
     * {@code array_replace}/{@code array_replace_recursive}/
     * {@code array_diff}/{@code array_intersect}/{@code array_diff_key}/
     * {@code array_intersect_key}/{@code array_diff_assoc}/
     * {@code array_intersect_assoc} on typed arrays — php-src
     * {@code ext/standard/array.c}. Soft-null / non-array args stay live
     * ({@code TypeError}). Zero-arg {@code array_replace*} /
     * {@code array_diff*} / {@code array_intersect*} stay live
     * ({@code ArgumentCountError}). Callback {@code array_u*} forms stay live.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayMergeDiffNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        $isMergeFamily = 'array_merge' === $name || 'array_merge_recursive' === $name;
        $isReplaceFamily = 'array_replace' === $name || 'array_replace_recursive' === $name;
        $isDiffFamily =
            'array_diff' === $name
            || 'array_intersect' === $name
            || 'array_diff_key' === $name
            || 'array_intersect_key' === $name
            || 'array_diff_assoc' === $name
            || 'array_intersect_assoc' === $name;
        if (!$isMergeFamily && !$isReplaceFamily && !$isDiffFamily) {
            return false;
        }
        // Zero-arg merge returns [] (php-src); replace/diff/intersect throw.
        if ([] === $callArgs) {
            return $isMergeFamily;
        }
        foreach ($callArgs as $arg) {
            if (!$arg instanceof Variable || !self::isTypedArrayArg($arg)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Discarded {@code in_array}/{@code array_search} on a typed haystack —
     * php-src {@code ext/standard/array.c}. Soft-null / non-array haystacks
     * stay live ({@code TypeError}). Soft-null {@code $strict} stays live
     * (deprecate). Needle is {@code Z_PARAM_ZVAL} (null / object / value-box OK).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayLookupNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        if ('in_array' !== $name && 'array_search' !== $name) {
            return false;
        }
        // needle + haystack required; optional strict; excess argc → ArgumentCountError.
        if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[3])) {
            return false;
        }
        if (!$callArgs[0] instanceof Variable || !$callArgs[1] instanceof Variable) {
            return false;
        }
        if (!self::isTypedArrayArg($callArgs[1])) {
            return false;
        }
        if (!isset($callArgs[2])) {
            return true;
        }

        return $callArgs[2] instanceof Variable
            && self::mathArgAllowsDiscardedElision($callArgs[2]);
    }

    /**
     * Discarded {@code array_pad}/{@code array_fill}/{@code array_fill_keys}/
     * {@code array_column} — php-src {@code ext/standard/array.c}. Soft-null /
     * non-array inputs stay live ({@code TypeError} / deprecate).
     * {@code array_pad} / {@code array_fill} require compile-time sizes that
     * cannot trip Zend {@code ValueError} guards.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayConstructNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        $name = strtolower($toCall->getName());
        switch ($name) {
            case 'array_pad':
                // 3-arg only — 4-arg pad_type / ArrayPadType stays live.
                if (!isset($callArgs[0], $callArgs[1], $callArgs[2]) || isset($callArgs[3])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !$callArgs[1] instanceof Variable
                    || !$callArgs[2] instanceof Variable
                ) {
                    return false;
                }
                if (!self::isTypedArrayArg($callArgs[0])) {
                    return false;
                }
                $length = $callArgs[1]->compileTimeLong;
                // VmArray::rejectOversizedPad: PHP_INT_MIN or |len|-inputSize > 1M.
                if (null === $length || \PHP_INT_MIN === $length) {
                    return false;
                }
                if (abs($length) > 1048576) {
                    return false;
                }

                return true;
            case 'array_fill':
                if (!isset($callArgs[0], $callArgs[1], $callArgs[2]) || isset($callArgs[3])) {
                    return false;
                }
                if (
                    !$callArgs[0] instanceof Variable
                    || !$callArgs[1] instanceof Variable
                    || !$callArgs[2] instanceof Variable
                ) {
                    return false;
                }
                if (!self::mathArgAllowsDiscardedElision($callArgs[0])) {
                    return false;
                }
                $count = $callArgs[1]->compileTimeLong;
                // php-src php_array_fill: count < 0 or count > 1048576 → ValueError.
                if (null === $count || $count < 0 || $count > 1048576) {
                    return false;
                }

                return true;
            case 'array_fill_keys':
                if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[2])) {
                    return false;
                }
                if (!$callArgs[0] instanceof Variable || !$callArgs[1] instanceof Variable) {
                    return false;
                }

                return self::isTypedArrayArg($callArgs[0]);
            case 'array_column':
                if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[3])) {
                    return false;
                }
                if (!$callArgs[0] instanceof Variable || !$callArgs[1] instanceof Variable) {
                    return false;
                }
                if (!self::isTypedArrayArg($callArgs[0])) {
                    return false;
                }
                if (!self::arrayColumnKeyAllowsDiscardedElision($callArgs[1])) {
                    return false;
                }
                if (!isset($callArgs[2])) {
                    return true;
                }

                return $callArgs[2] instanceof Variable
                    && self::arrayColumnKeyAllowsDiscardedElision($callArgs[2]);
            default:
                return false;
        }
    }

    /**
     * Discarded {@code array_combine} — php-src {@code ext/standard/array.c}
     * {@code PHP_FUNCTION(array_combine)}. Length mismatch is a {@code ValueError}.
     * Only equal-length non-empty compile-time packs are elided.
     * {@code compileTimeEmptyArrayLiteral} is also set on {@code array} RECV
     * slots, so empty-literal alone is not a size proof.
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureArrayCombineNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('array_combine' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[2])) {
            return false;
        }
        if (!$callArgs[0] instanceof Variable || !$callArgs[1] instanceof Variable) {
            return false;
        }
        $keys = $callArgs[0];
        $values = $callArgs[1];
        if (!self::isTypedArrayArg($keys) || !self::isTypedArrayArg($values)) {
            return false;
        }
        // Empty compileTimeArray/Assoc ([]) is an in-progress marker; RECV
        // slots may also carry compileTimeEmptyArrayLiteral — neither proves
        // equal runtime lengths (ValueError).
        if (
            null !== $keys->compileTimeArray
            && null !== $values->compileTimeArray
            && \count($keys->compileTimeArray) > 0
            && \count($keys->compileTimeArray) === \count($values->compileTimeArray)
        ) {
            return true;
        }
        if (
            null !== $keys->compileTimeAssoc
            && null !== $values->compileTimeAssoc
            && \count($keys->compileTimeAssoc) > 0
            && \count($keys->compileTimeAssoc) === \count($values->compileTimeAssoc)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Discarded {@code range} — php-src {@code ext/standard/array.c}
     * {@code PHP_FUNCTION(range)}. Two typed numeric endpoints use default
     * step ±1 (no ValueError). Three-arg form requires compile-time longs that
     * pass the zero / increasing-negative / oversized step checks (peer
     * {@see \PHPCompiler\ext\standard\RangeIntJitHelper::intRangeCopy}).
     *
     * @param array<int, Variable> $callArgs
     */
    private static function tryElidePureRangeNoSideEffect(?Call $toCall, array $callArgs): bool
    {
        if (!$toCall instanceof CoreFuncInternal) {
            return false;
        }
        if ('range' !== strtolower($toCall->getName())) {
            return false;
        }
        if (!isset($callArgs[0], $callArgs[1]) || isset($callArgs[3])) {
            return false;
        }
        if (!$callArgs[0] instanceof Variable || !$callArgs[1] instanceof Variable) {
            return false;
        }
        if (
            !self::mathArgAllowsDiscardedElision($callArgs[0])
            || !self::mathArgAllowsDiscardedElision($callArgs[1])
        ) {
            return false;
        }
        if (!isset($callArgs[2])) {
            // Default step is select(±1) — never 0 / never oversized vs span.
            return true;
        }
        if (!$callArgs[2] instanceof Variable) {
            return false;
        }
        $start = $callArgs[0]->compileTimeLong;
        $end = $callArgs[1]->compileTimeLong;
        $step = $callArgs[2]->compileTimeLong;
        if (null === $start || null === $end || null === $step) {
            // Runtime step / endpoints can still ValueError.
            return false;
        }

        return self::compileTimeRangeStepAllowsDiscardedElision($start, $end, $step);
    }

    /**
     * Mirror {@see \PHPCompiler\ext\standard\RangeIntJitHelper::intRangeCopy}
     * ValueError guards for discarded elision (PROFILE-agnostic: reject every
     * shape that can throw on any supported profile).
     */
    private static function compileTimeRangeStepAllowsDiscardedElision(
        int $start,
        int $end,
        int $step
    ): bool {
        if (0 === $step) {
            return false;
        }
        // php-src: only end > start rejects a negative step; equal endpoints stay a singleton.
        if ($start < $end && $step < 0) {
            return false;
        }
        if ($start !== $end) {
            $span = $start > $end ? ($start - $end) : ($end - $start);
            $stepAbs = $step < 0 ? -$step : $step;
            if ($span < $stepAbs) {
                return false;
            }
        }

        return true;
    }


    /**
     * {@code array_column} column_key / index_key: null, typed string, or typed
     * long — objects / generic value-boxes stay live ({@code TypeError}).
     */
    private static function arrayColumnKeyAllowsDiscardedElision(Variable $arg): bool
    {
        if ($arg->isNullConstant || Variable::TYPE_NULL === $arg->type) {
            return true;
        }
        if (self::stringArgAllowsDiscardedElision($arg)) {
            return true;
        }

        return self::mathArgAllowsDiscardedElision($arg);
    }
}
