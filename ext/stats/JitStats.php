<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MathSqrt;
use PHPCompiler\JIT\Builtin\Stats;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for stats_* builtins via __compiler_stats_* runtime (#5748 / #28080).
 *
 * `stats_standard_deviation` takes √variance via {@see MathSqrt::invoke} — not libc
 * `sqrt` — so LibcExtern can drop math decls (#28808 / MathSqrt #27888).
 */
final class JitStats
{
    public static function variance(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 1, 2, 'stats_variance');
        Stats::ensureLinked($context);
        $sample = self::sampleFlag($context, $args, 1);
        $ht = self::loadArray($context, $args[0], 'stats_variance', 'a');

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_variance'),
                $ht,
                $sample
            )
        );
    }

    public static function standardDeviation(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 1, 2, 'stats_standard_deviation');
        $sample = self::sampleFlag($context, $args, 1);
        $ht = self::loadArray($context, $args[0], 'stats_standard_deviation', 'a');
        Stats::ensureLinked($context);

        $var = $context->builder->call(
            $context->lookupFunction('__compiler_stats_variance'),
            $ht,
            $sample
        );
        $fail = $context->builder->fcmp(Builder::REAL_UNO, $var, $var);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'stats_stddev_fail');
        $okBlock = BasicBlockHelper::append($context, 'stats_stddev_ok');
        $doneBlock = BasicBlockHelper::append($context, 'stats_stddev_done');
        $context->builder->branchIf($fail, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $sqrtVal = MathSqrt::invoke($context, $var);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $ptr,
            $sqrtVal
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    public static function covariance(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 2, 3, 'stats_covariance');
        Stats::ensureLinked($context);
        $sample = self::sampleFlag($context, $args, 2);
        $htA = self::loadArray($context, $args[0], 'stats_covariance', 'a');
        $htB = self::loadArray($context, $args[1], 'stats_covariance', 'b');

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_covariance'),
                $htA,
                $htB,
                $sample
            )
        );
    }

    public static function absoluteDeviation(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 1, 1, 'stats_absolute_deviation');
        Stats::ensureLinked($context);
        $ht = self::loadArray($context, $args[0], 'stats_absolute_deviation', 'a');

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_absolute_deviation'),
                $ht
            )
        );
    }

    public static function harmonicMean(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 1, 1, 'stats_harmonic_mean');
        Stats::ensureLinked($context);
        $ht = self::loadArray($context, $args[0], 'stats_harmonic_mean', 'a');

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_harmonic_mean'),
                $ht
            )
        );
    }

    public static function skew(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 1, 1, 'stats_skew');
        Stats::ensureLinked($context);
        $ht = self::loadArray($context, $args[0], 'stats_skew', 'a');

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_skew'),
                $ht
            )
        );
    }

    public static function kurtosis(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 1, 1, 'stats_kurtosis');
        Stats::ensureLinked($context);
        $ht = self::loadArray($context, $args[0], 'stats_kurtosis', 'a');

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_kurtosis'),
                $ht
            )
        );
    }

    public static function percentile(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 2, 2, 'stats_stat_percentile');
        Stats::ensureLinked($context);
        $ht = self::loadArray($context, $args[0], 'stats_stat_percentile', 'arr');
        $perc = self::loadDouble($context, $args[1], 'stats_stat_percentile', 'perc');

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_percentile'),
                $ht,
                $perc
            )
        );
    }

    public static function correlation(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 2, 2, 'stats_stat_correlation');
        Stats::ensureLinked($context);
        $htA = self::loadArray($context, $args[0], 'stats_stat_correlation', 'arr1');
        $htB = self::loadArray($context, $args[1], 'stats_stat_correlation', 'arr2');

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_correlation'),
                $htA,
                $htB
            )
        );
    }

    public static function powersum(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 2, 2, 'stats_stat_powersum');
        Stats::ensureLinked($context);
        $ht = self::loadArray($context, $args[0], 'stats_stat_powersum', 'arr');
        $power = self::loadDouble($context, $args[1], 'stats_stat_powersum', 'power');

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_powersum'),
                $ht,
                $power
            )
        );
    }

    public static function innerproduct(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 2, 2, 'stats_stat_innerproduct');
        Stats::ensureLinked($context);
        $htA = self::loadArray($context, $args[0], 'stats_stat_innerproduct', 'arr1');
        $htB = self::loadArray($context, $args[1], 'stats_stat_innerproduct', 'arr2');

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_innerproduct'),
                $htA,
                $htB
            )
        );
    }

    public static function factorial(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 1, 1, 'stats_stat_factorial');
        Stats::ensureLinked($context);
        $n = JitLongArg::lower($context, $args[0], 'stats_stat_factorial n');

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_factorial'),
                $n
            ),
            false
        );
    }

    public static function binomialCoef(Context $context, JITVariable ...$args): Value
    {
        self::requireArgc($args, 2, 2, 'stats_stat_binomial_coef');
        Stats::ensureLinked($context);
        $x = JitLongArg::lower($context, $args[0], 'stats_stat_binomial_coef x');
        $n = JitLongArg::lower($context, $args[1], 'stats_stat_binomial_coef n');

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_binomial_coef'),
                $x,
                $n
            ),
            false
        );
    }

    /**
     * dens_* / dens_pmf_* via op-coded dispatcher (#29587).
     *
     * @param array<int, JITVariable> $args
     */
    public static function dens(Context $context, int $op, int $arity, JITVariable ...$args): Value
    {
        self::requireArgc($args, $arity, $arity, 'stats_dens');
        Stats::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $opVal = $i64->constInt($op, false);
        $a = self::loadDouble($context, $args[0], 'stats_dens', 'a');
        $b = $arity >= 2
            ? self::loadDouble($context, $args[1], 'stats_dens', 'b')
            : $double->constReal(0.0);
        $c = $arity >= 3
            ? self::loadDouble($context, $args[2], 'stats_dens', 'c')
            : $double->constReal(0.0);
        $d = $arity >= 4
            ? self::loadDouble($context, $args[3], 'stats_dens', 'd')
            : $double->constReal(0.0);

        return self::boxStatsResult(
            $context,
            $context->builder->call(
                $context->lookupFunction('__compiler_stats_dens'),
                $opVal,
                $a,
                $b,
                $c,
                $d
            )
        );
    }

    /** @param array<int, JITVariable> $args */
    private static function requireArgc(array $args, int $min, int $max, string $function): void
    {
        $argc = \count($args);
        if ($argc < $min || $argc > $max) {
            throw new \ArgumentCountError(
                $function.'() expects at least '.$min.' argument'.(1 === $min ? '' : 's')
                .', '.\max(0, $argc - $min).' given'
            );
        }
    }

    /** @param array<int, JITVariable> $args */
    private static function sampleFlag(Context $context, array $args, int $index): Value
    {
        $i1 = $context->getTypeFromString('int1');
        if (!isset($args[$index])) {
            return $i1->constInt(0, false);
        }

        return JitBoolArg::lower($context, $args[$index], 'stats sample flag');
    }

    private static function loadArray(
        Context $context,
        JITVariable $array,
        string $function,
        string $label
    ): Value {
        if (0 === ($array->type & (JITVariable::TYPE_HASHTABLE | JITVariable::IS_NATIVE_ARRAY))) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($%s) must be of type array, %s given',
                $function,
                $label,
                self::jitTypeName($array->type)
            ));
        }

        return ArrayBuiltinHelper::loadHashTable($context, $array);
    }

    private static function loadDouble(
        Context $context,
        JITVariable $arg,
        string $function,
        string $label
    ): Value {
        $double = $context->getTypeFromString('double');
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type || JITVariable::TYPE_FLOAT === $arg->type) {
            return $context->builder->load($arg->value);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type || JITVariable::TYPE_INTEGER === $arg->type) {
            return $context->builder->siToFp(JitLongArg::lower($context, $arg, $function.' '.$label), $double);
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument ($%s) must be of type float, %s given',
            $function,
            $label,
            self::jitTypeName($arg->type)
        ));
    }

    private static function boxStatsResult(Context $context, Value $raw, bool $nanMeansFalse = true): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (!$nanMeansFalse) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $ptr,
                $raw
            );

            return $ptr;
        }
        $fail = $context->builder->fcmp(Builder::REAL_UNO, $raw, $raw);
        $failBlock = BasicBlockHelper::append($context, 'stats_result_fail');
        $okBlock = BasicBlockHelper::append($context, 'stats_result_ok');
        $doneBlock = BasicBlockHelper::append($context, 'stats_result_done');
        $context->builder->branchIf($fail, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $ptr,
            $raw
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function jitTypeName(int $type): string
    {
        if (JITVariable::TYPE_STRING === $type) {
            return 'string';
        }
        if (JITVariable::TYPE_NATIVE_LONG === $type || JITVariable::TYPE_INTEGER === $type) {
            return 'int';
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $type || JITVariable::TYPE_FLOAT === $type) {
            return 'float';
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $type) {
            return 'bool';
        }

        return 'mixed';
    }
}
