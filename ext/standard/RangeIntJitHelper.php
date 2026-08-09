<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * range() int path — VM SSOT (#13502, php-in-PHP).
 *
 * Self-contained (no {@see VmRange} call). Thin AOT/JIT builds the packed list in
 * {@see \PHPCompiler\JIT\Builtin\RangeIntRuntime} via `__hashtable__setLongAt`
 * (NestedJIT of this helper's HashTable hangs/segfaults — #26956 / peer #26910).
 * php-src: ext/standard/array.c — php_range()
 */
final class RangeIntJitHelper
{
    /** php-src 8.2 uses this text for both zero step and step larger than the span. */
    private const STEP_RANGE_ERROR_LEGACY = 'range(): Argument #3 ($step) must not exceed the specified range';

    /** php-src 8.3+ zend_argument_value_error(3, "cannot be 0"). */
    private const STEP_ZERO_ERROR_83 = 'range(): Argument #3 ($step) cannot be 0';

    /** php-src 8.3+ boundary_error. */
    private const STEP_OVERSIZED_ERROR_83 = 'range(): Argument #3 ($step) must be less than the range spanned by argument #1 ($start) and argument #2 ($end)';

    /** php-src 8.3+ negative_step_error (strictly increasing only; #29351). */
    private const STEP_INCREASING_NEGATIVE_ERROR_83 = 'range(): Argument #3 ($step) must be greater than 0 for increasing ranges';

    /** Zero-step ValueError text (PROFILE≥8.3 split; #28537). */
    public static function stepZeroErrorMessage(): string
    {
        if (CompilerVersion::supportsRangeStepSplitErrors()) {
            return self::STEP_ZERO_ERROR_83;
        }

        return self::STEP_RANGE_ERROR_LEGACY;
    }

    /** Oversized-step ValueError text (PROFILE≥8.3 split; #28537). */
    public static function stepOversizedErrorMessage(): string
    {
        if (CompilerVersion::supportsRangeStepSplitErrors()) {
            return self::STEP_OVERSIZED_ERROR_83;
        }

        return self::STEP_RANGE_ERROR_LEGACY;
    }

    /** Increasing-range negative-step ValueError text (PROFILE≥8.3; #29351). */
    public static function stepIncreasingNegativeErrorMessage(): string
    {
        return self::STEP_INCREASING_NEGATIVE_ERROR_83;
    }

    public static function intRangeCopy(int $start, int $end, int $step): HashTable
    {
        if (0 === $step) {
            throw new \ValueError(self::stepZeroErrorMessage());
        }
        // php-src: only end > start rejects a negative step; equal endpoints stay a singleton.
        if ($start < $end && $step < 0) {
            if (CompilerVersion::supportsRangeIncreasingNegativeStepError()) {
                throw new \ValueError(self::stepIncreasingNegativeErrorMessage());
            }
            $step = -$step;
        } elseif ($start > $end && $step > 0) {
            $step = -$step;
        }
        if ($start !== $end) {
            $span = $start > $end ? ($start - $end) : ($end - $start);
            $stepAbs = $step < 0 ? -$step : $step;
            if ($span < $stepAbs) {
                throw new \ValueError(self::stepOversizedErrorMessage());
            }
        }

        if ($step > 0) {
            $count = intdiv($end - $start, $step) + 1;
        } else {
            $count = intdiv($start - $end, -$step) + 1;
        }
        if ($count < 1) {
            $count = 0;
        }

        $ht = new HashTable();
        for ($i = 0; $i < $count; ++$i) {
            $stored = new Variable();
            $stored->int($start + ($i * $step));
            $ht->addIndex($i, $stored);
        }

        return $ht;
    }

    /**
     * Build int range after step is non-zero and sign-normalized (VM {@see VmRange}).
     */
    public static function buildIntRange(int $start, int $end, int $step): HashTable
    {
        return self::intRangeCopy($start, $end, $step);
    }
}
