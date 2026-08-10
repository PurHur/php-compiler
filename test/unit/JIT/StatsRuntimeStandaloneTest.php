<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Stats;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * stats_* LLVM helpers must lower without C symbols (#5748).
 *
 * @group aot-lint
 */
final class StatsRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesStatsHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        Stats::ensureLinked($ctx);

        foreach ([
            '__compiler_stats_variance',
            '__compiler_stats_covariance',
            '__compiler_stats_absolute_deviation',
            '__compiler_stats_harmonic_mean',
            '__compiler_stats_skew',
            '__compiler_stats_kurtosis',
            '__compiler_stats_percentile',
            '__compiler_stats_correlation',
            '__compiler_stats_powersum',
            '__compiler_stats_innerproduct',
            '__compiler_stats_factorial',
            '__compiler_stats_binomial_coef',
            '__compiler_stats_dens',
            '__compiler_stats_cdf',
            '__compiler_stats_rand_setall',
            '__compiler_stats_rand_getsd',
            '__compiler_stats_rand_ranf',
            '__compiler_stats_rand_gen_normal',
            '__compiler_stats_rand_gen_iuniform',
            '__compiler_stats_rand_gen',
            '__compiler_stats_rand_ibinomial',
            '__compiler_stats_rand_ibinomial_negative',
            '__compiler_stats_rand_gen_ipoisson',
            '__compiler_stats_rand_phrase_to_seeds',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
