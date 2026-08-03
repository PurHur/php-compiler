<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

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
    private const STEP_RANGE_ERROR = 'range(): Argument #3 ($step) must not exceed the specified range';

    public static function intRangeCopy(int $start, int $end, int $step): HashTable
    {
        if (0 === $step) {
            throw new \ValueError(self::STEP_RANGE_ERROR);
        }
        if ($start <= $end && $step < 0) {
            $step = -$step;
        } elseif ($start > $end && $step > 0) {
            $step = -$step;
        }
        if ($start !== $end) {
            $span = $start > $end ? ($start - $end) : ($end - $start);
            $stepAbs = $step < 0 ? -$step : $step;
            if ($span < $stepAbs) {
                throw new \ValueError(self::STEP_RANGE_ERROR);
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
