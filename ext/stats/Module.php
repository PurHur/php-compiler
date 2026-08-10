<?php

declare(strict_types=1);

namespace PHPCompiler\ext\stats;

use PHPCompiler\ModuleAbstract;

/**
 * stats extension module entry (PECL stats; issue #5748, #26743).
 *
 * Algorithms in {@see VmStats} — PHP-in-PHP, no runtime/*.c.
 * Advertise stats_* / extension_loaded('stats') only when
 * {@see StatsExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        if (!StatsExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new stats_standard_deviation(),
            new stats_variance(),
            new stats_covariance(),
            new stats_absolute_deviation(),
            new stats_harmonic_mean(),
            new stats_skew(),
            new stats_kurtosis(),
            new stats_stat_percentile(),
            new stats_stat_correlation(),
            new stats_stat_powersum(),
            new stats_stat_innerproduct(),
            new stats_stat_factorial(),
            new stats_stat_binomial_coef(),
            new stats_dens_normal(),
            new stats_dens_cauchy(),
            new stats_dens_laplace(),
            new stats_dens_logistic(),
            new stats_dens_beta(),
            new stats_dens_weibull(),
            new stats_dens_uniform(),
            new stats_dens_chisquare(),
            new stats_dens_t(),
            new stats_dens_gamma(),
            new stats_dens_exponential(),
            new stats_dens_f(),
            new stats_dens_pmf_binomial(),
            new stats_dens_pmf_poisson(),
            new stats_dens_pmf_negative_binomial(),
            new stats_dens_pmf_hypergeometric(),
            new stats_cdf_normal(),
            new stats_cdf_t(),
            new stats_cdf_chisquare(),
            new stats_cdf_gamma(),
            new stats_cdf_beta(),
            new stats_cdf_f(),
            new stats_cdf_poisson(),
            new stats_cdf_exponential(),
            new stats_cdf_binomial(),
            new stats_cdf_laplace(),
            new stats_cdf_cauchy(),
            new stats_cdf_logistic(),
            new stats_cdf_weibull(),
            new stats_cdf_uniform(),
            new stats_cdf_negative_binomial(),
            new stats_rand_setall(),
            new stats_rand_getsd(),
            new stats_rand_ranf(),
            new stats_rand_gen_normal(),
            new stats_rand_gen_iuniform(),
            new stats_rand_gen_beta(),
            new stats_rand_gen_exponential(),
            new stats_rand_gen_gamma(),
            new stats_rand_gen_chisquare(),
            new stats_rand_gen_f(),
            new stats_rand_gen_funiform(),
            new stats_rand_ibinomial(),
            new stats_rand_ibinomial_negative(),
            new stats_rand_gen_ipoisson(),
            new stats_rand_gen_t(),
            new stats_rand_phrase_to_seeds(),
        ];
    }
}
