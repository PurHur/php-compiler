<?php

declare(strict_types=1);

// Maintainer repro: #26743 — stats phantom on default profile (Zend without pecl-stats).
echo 'ext=', extension_loaded('stats') ? '1' : '0', "\n";
echo 'cov=', function_exists('stats_covariance') ? '1' : '0', "\n";
echo 'sd=', function_exists('stats_standard_deviation') ? '1' : '0', "\n";
echo 'var=', function_exists('stats_variance') ? '1' : '0', "\n";
$internal = get_defined_functions()['internal'];
$leak = 0;
foreach (['stats_covariance', 'stats_standard_deviation', 'stats_variance'] as $fn) {
    if (\in_array($fn, $internal, true)) {
        $leak = 1;
        break;
    }
}
echo 'leak=', $leak, "\n";
