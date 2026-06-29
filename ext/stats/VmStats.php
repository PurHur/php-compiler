<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\ext\standard\TriggerErrorJitHelper;
use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Descriptive statistics — PECL stats algorithms in PHP (php-src ext/stats; issue #5748).
 */
final class VmStats
{
    /**
     * @return list<float>
     */
    public static function coerceNumericArray(HashTable $ht, ?Frame $frame = null): array
    {
        $values = [];
        foreach ($ht->iterate(true) as $value) {
            $v = $value->resolveIndirect();
            if (Variable::TYPE_INTEGER === $v->type) {
                $values[] = (float) $v->toInt();
                continue;
            }
            if (Variable::TYPE_FLOAT === $v->type) {
                $values[] = $v->toFloat();
                continue;
            }
            if (Variable::TYPE_STRING === $v->type && is_numeric($v->toString())) {
                $values[] = (float) $v->toString();
                continue;
            }
            if (Variable::TYPE_BOOLEAN === $v->type) {
                $values[] = (float) (int) $v->toBool();
                continue;
            }

            throw new \TypeError('stats functions expect numeric array elements');
        }

        return $values;
    }

    /**
     * PECL stats_variance — mutates array elements to float in php-src; we copy only.
     *
     * @return float|false
     */
    public static function variance(array $values, bool $sample, ?Frame $frame, string $function)
    {
        $n = \count($values);
        if (0 === $n) {
            self::warning($frame, 'The array has zero elements');

            return false;
        }
        if ($sample && 1 === $n) {
            self::warning($frame, 'The array has only 1 element');

            return false;
        }

        $mean = array_sum($values) / $n;
        $carry = 0.0;
        foreach ($values as $val) {
            $d = $val - $mean;
            $carry += $d * $d;
        }
        $divisor = $sample ? ($n - 1) : $n;

        return $carry / $divisor;
    }

    /**
     * @return float|false
     */
    public static function standardDeviation(array $values, bool $sample, ?Frame $frame, string $function)
    {
        $var = self::variance($values, $sample, $frame, $function);
        if (false === $var) {
            return false;
        }

        return \sqrt($var);
    }

    /**
     * @return float|false
     */
    public static function covariance(array $a, array $b, bool $sample, ?Frame $frame, string $function)
    {
        $na = \count($a);
        $nb = \count($b);
        if (0 === $na || 0 === $nb) {
            self::warning($frame, 'The array has zero elements');

            return false;
        }
        if ($na !== $nb) {
            self::warning($frame, 'The two arrays have unequal size');

            return false;
        }
        if ($sample && 1 === $na) {
            self::warning($frame, 'The array has only 1 element');

            return false;
        }

        $meanA = array_sum($a) / $na;
        $meanB = array_sum($b) / $nb;
        $carry = 0.0;
        for ($i = 0; $i < $na; ++$i) {
            $carry += ($a[$i] - $meanA) * ($b[$i] - $meanB);
        }
        $divisor = $sample ? ($na - 1) : $na;

        return $carry / $divisor;
    }

    private static function warning(?Frame $frame, string $message): void
    {
        if (null !== $frame?->vmContext) {
            $frame->vmContext->errors->triggerError(
                $message,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );

            return;
        }
        TriggerErrorJitHelper::warning($message);
    }
}
