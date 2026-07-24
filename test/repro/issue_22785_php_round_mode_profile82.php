<?php
/**
 * #22785 — PHP_ROUND_CEILING/FLOOR/TOWARD_ZERO/AWAY_FROM_ZERO are PHP 8.4+ only.
 * Run: PHP_COMPILER_PROFILE=8.2 php bin/vm.php test/repro/issue_22785_php_round_mode_profile82.php
 */
foreach ([
    'PHP_ROUND_HALF_UP',
    'PHP_ROUND_CEILING',
    'PHP_ROUND_FLOOR',
    'PHP_ROUND_TOWARD_ZERO',
    'PHP_ROUND_AWAY_FROM_ZERO',
] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', "\n";
}
