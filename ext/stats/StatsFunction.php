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
        if (true === $result) {
            $frame->returnVar->bool(true);

            return;
        }
        if (\is_array($result)) {
            $ht = new \PHPCompiler\VM\HashTable();
            foreach ($result as $item) {
                $cell = new Variable();
                if (\is_int($item)) {
                    $cell->int($item);
                } else {
                    $cell->float((float) $item);
                }
                $ht->append($cell);
            }
            $frame->returnVar->array($ht);

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
            'stats_dens_normal' => JitStats::dens($context, VmStatsDens::OP_NORMAL, 3, ...$args),
            'stats_dens_cauchy' => JitStats::dens($context, VmStatsDens::OP_CAUCHY, 3, ...$args),
            'stats_dens_laplace' => JitStats::dens($context, VmStatsDens::OP_LAPLACE, 3, ...$args),
            'stats_dens_logistic' => JitStats::dens($context, VmStatsDens::OP_LOGISTIC, 3, ...$args),
            'stats_dens_beta' => JitStats::dens($context, VmStatsDens::OP_BETA, 3, ...$args),
            'stats_dens_weibull' => JitStats::dens($context, VmStatsDens::OP_WEIBULL, 3, ...$args),
            'stats_dens_uniform' => JitStats::dens($context, VmStatsDens::OP_UNIFORM, 3, ...$args),
            'stats_dens_chisquare' => JitStats::dens($context, VmStatsDens::OP_CHISQUARE, 2, ...$args),
            'stats_dens_t' => JitStats::dens($context, VmStatsDens::OP_T, 2, ...$args),
            'stats_dens_gamma' => JitStats::dens($context, VmStatsDens::OP_GAMMA, 3, ...$args),
            'stats_dens_exponential' => JitStats::dens($context, VmStatsDens::OP_EXPONENTIAL, 2, ...$args),
            'stats_dens_f' => JitStats::dens($context, VmStatsDens::OP_F, 3, ...$args),
            'stats_dens_pmf_binomial' => JitStats::dens($context, VmStatsDens::OP_PMF_BINOMIAL, 3, ...$args),
            'stats_dens_pmf_poisson' => JitStats::dens($context, VmStatsDens::OP_PMF_POISSON, 2, ...$args),
            'stats_dens_pmf_negative_binomial' => JitStats::dens($context, VmStatsDens::OP_PMF_NEGBIN, 3, ...$args),
            'stats_dens_pmf_hypergeometric' => JitStats::dens($context, VmStatsDens::OP_PMF_HYPER, 4, ...$args),
            'stats_cdf_normal' => JitStats::cdf($context, VmStatsCdf::OP_NORMAL, 4, ...$args),
            'stats_cdf_t' => JitStats::cdf($context, VmStatsCdf::OP_T, 3, ...$args),
            'stats_cdf_chisquare' => JitStats::cdf($context, VmStatsCdf::OP_CHISQUARE, 3, ...$args),
            'stats_cdf_gamma' => JitStats::cdf($context, VmStatsCdf::OP_GAMMA, 4, ...$args),
            'stats_cdf_beta' => JitStats::cdf($context, VmStatsCdf::OP_BETA, 4, ...$args),
            'stats_cdf_f' => JitStats::cdf($context, VmStatsCdf::OP_F, 4, ...$args),
            'stats_cdf_poisson' => JitStats::cdf($context, VmStatsCdf::OP_POISSON, 3, ...$args),
            'stats_cdf_exponential' => JitStats::cdf($context, VmStatsCdf::OP_EXPONENTIAL, 3, ...$args),
            'stats_rand_setall' => JitStats::randSetall($context, ...$args),
            'stats_rand_getsd' => JitStats::randGetsd($context, ...$args),
            'stats_rand_ranf' => JitStats::randRanf($context, ...$args),
            'stats_rand_gen_normal' => JitStats::randGenNormal($context, ...$args),
            'stats_rand_gen_iuniform' => JitStats::randGenIuniform($context, ...$args),
            default => throw new \LogicException('unsupported stats builtin: '.$this->getName()),
        };
    }

    /** @return float|int|false|true|array */
    abstract protected function compute(Frame $frame): float|int|bool|array;

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
