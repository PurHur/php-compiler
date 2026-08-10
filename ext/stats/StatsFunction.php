<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** Shared VM wiring for stats builtins (PECL stats; issue #5748 / #28080). */
abstract class StatsFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $result = $this->compute($frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_int($result)) {
            $frame->returnVar->int($result);

            return;
        }
        $frame->returnVar->float($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return match ($this->getName()) {
            'stats_variance' => JitStats::variance($context, ...$args),
            'stats_standard_deviation' => JitStats::standardDeviation($context, ...$args),
            'stats_covariance' => JitStats::covariance($context, ...$args),
            'stats_absolute_deviation' => JitStats::absoluteDeviation($context, ...$args),
            'stats_harmonic_mean' => JitStats::harmonicMean($context, ...$args),
            'stats_skew' => JitStats::skew($context, ...$args),
            'stats_kurtosis' => JitStats::kurtosis($context, ...$args),
            'stats_stat_percentile' => JitStats::percentile($context, ...$args),
            'stats_stat_correlation' => JitStats::correlation($context, ...$args),
            'stats_stat_powersum' => JitStats::powersum($context, ...$args),
            'stats_stat_innerproduct' => JitStats::innerproduct($context, ...$args),
            'stats_stat_factorial' => JitStats::factorial($context, ...$args),
            'stats_stat_binomial_coef' => JitStats::binomialCoef($context, ...$args),
            default => throw new \LogicException('unsupported stats builtin: '.$this->getName()),
        };
    }

    /** @return float|int|false */
    abstract protected function compute(Frame $frame): float|int|bool;

    protected function requireArrayArg(Frame $frame, int $index, string $label): Variable
    {
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array, %s given',
                $this->getName(),
                $index + 1,
                $label,
                self::debugTypeName($var)
            ));
        }

        return $var;
    }

    protected function requireFloatArg(Frame $frame, int $index, string $label): float
    {
        $var = $frame->calledArgs[$index]->resolveIndirect();

        return match ($var->type) {
            Variable::TYPE_INTEGER => (float) $var->toInt(),
            Variable::TYPE_FLOAT => $var->toFloat(),
            default => throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type float, %s given',
                $this->getName(),
                $index + 1,
                $label,
                self::debugTypeName($var)
            )),
        };
    }

    protected function requireIntArg(Frame $frame, int $index, string $label): int
    {
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $this->getName(),
                $index + 1,
                $label,
                self::debugTypeName($var)
            ));
        }

        return $var->toInt();
    }

    protected function optionalSampleFlag(Frame $frame, int $index): bool
    {
        if (\count($frame->calledArgs) <= $index) {
            return false;
        }
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($sample) must be of type bool, %s given',
                $this->getName(),
                $index + 1,
                self::debugTypeName($var)
            ));
        }

        return $var->toBool();
    }

    private static function debugTypeName(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
