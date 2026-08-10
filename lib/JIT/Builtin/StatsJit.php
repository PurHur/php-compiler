<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for __compiler_stats_* via StatsJitHelper PHP (#13792 / #28080).
 *
 * Replaces ~407-line Welford LLVM; SSOT {@see \PHPCompiler\ext\stats\VmStats}.
 * php-src: pecl-math-stats — descriptive statistics
 */
final class StatsJit
{
    private const ABI_VARIANCE = '__compiler_stats_variance';

    private const ABI_COVARIANCE = '__compiler_stats_covariance';

    private const ABI_ABS_DEV = '__compiler_stats_absolute_deviation';

    private const ABI_HARM = '__compiler_stats_harmonic_mean';

    private const ABI_SKEW = '__compiler_stats_skew';

    private const ABI_KURT = '__compiler_stats_kurtosis';

    private const ABI_PERC = '__compiler_stats_percentile';

    private const ABI_CORR = '__compiler_stats_correlation';

    private const ABI_POWERSUM = '__compiler_stats_powersum';

    private const ABI_INNER = '__compiler_stats_innerproduct';

    private const ABI_FACT = '__compiler_stats_factorial';

    private const ABI_BINOM = '__compiler_stats_binomial_coef';

    private const HELPER_PATH = '/ext/stats/StatsJitHelper.php';

    private const VARIANCE_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::variance';

    private const COVARIANCE_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::covariance';

    private const ABS_DEV_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::absoluteDeviation';

    private const HARM_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::harmonicMean';

    private const SKEW_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::skew';

    private const KURT_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::kurtosis';

    private const PERC_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::percentile';

    private const CORR_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::correlation';

    private const POWERSUM_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::powersum';

    private const INNER_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::innerproduct';

    private const FACT_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::factorial';

    private const BINOM_HELPER = 'PHPCompiler\\ext\\stats\\StatsJitHelper::binomialCoef';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VARIANCE_HELPER,
        self::COVARIANCE_HELPER,
        self::ABS_DEV_HELPER,
        self::HARM_HELPER,
        self::SKEW_HELPER,
        self::KURT_HELPER,
        self::PERC_HELPER,
        self::CORR_HELPER,
        self::POWERSUM_HELPER,
        self::INNER_HELPER,
        self::FACT_HELPER,
        self::BINOM_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        self::ABI_VARIANCE,
        self::ABI_COVARIANCE,
        self::ABI_ABS_DEV,
        self::ABI_HARM,
        self::ABI_SKEW,
        self::ABI_KURT,
        self::ABI_PERC,
        self::ABI_CORR,
        self::ABI_POWERSUM,
        self::ABI_INNER,
        self::ABI_FACT,
        self::ABI_BINOM,
    ];

    public static function implement(Context $context): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');

        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_VARIANCE,
            'stats_variance_bridge_entry',
            [$htPtr, $i1],
            $double,
            self::VARIANCE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13792'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COVARIANCE,
            'stats_covariance_bridge_entry',
            [$htPtr, $htPtr, $i1],
            $double,
            self::COVARIANCE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#13792'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ABS_DEV,
            'stats_abs_dev_bridge_entry',
            [$htPtr],
            $double,
            self::ABS_DEV_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28080'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_HARM,
            'stats_harm_bridge_entry',
            [$htPtr],
            $double,
            self::HARM_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28080'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SKEW,
            'stats_skew_bridge_entry',
            [$htPtr],
            $double,
            self::SKEW_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28080'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_KURT,
            'stats_kurt_bridge_entry',
            [$htPtr],
            $double,
            self::KURT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28080'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_PERC,
            'stats_perc_bridge_entry',
            [$htPtr, $double],
            $double,
            self::PERC_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28080'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CORR,
            'stats_corr_bridge_entry',
            [$htPtr, $htPtr],
            $double,
            self::CORR_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28080'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_POWERSUM,
            'stats_powersum_bridge_entry',
            [$htPtr, $double],
            $double,
            self::POWERSUM_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28080'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_INNER,
            'stats_inner_bridge_entry',
            [$htPtr, $htPtr],
            $double,
            self::INNER_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28080'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FACT,
            'stats_fact_bridge_entry',
            [$i64],
            $double,
            self::FACT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28080'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_BINOM,
            'stats_binom_bridge_entry',
            [$i64, $i64],
            $double,
            self::BINOM_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28080'
        );
        self::ensureLibcSqrt($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function ensureLibcSqrt(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        $name = 'sqrt';
        if (null === $context->module->getNamedFunction($name)) {
            $fn = $context->module->addFunction(
                $name,
                $context->context->functionType($double, false, $double)
            );
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
    }
}
