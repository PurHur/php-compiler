<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MathSqrt;
use PHPCompiler\JIT\Builtin\Stats;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for stats_* builtins via __compiler_stats_* runtime (#5748).
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

    private static function boxStatsResult(Context $context, Value $raw): Value
    {
        $fail = $context->builder->fcmp(Builder::REAL_UNO, $raw, $raw);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
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
