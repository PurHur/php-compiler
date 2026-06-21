<?php

declare(strict_types=1);

/**
 * Issue #10470 — print_r() non-finite float stringification.
 *
 * php-src: ext/standard/basic_functions.c — php_print_zval / print_r double branch.
 *
 * Verify:
 *   php bin/vm.php test/repro/maintainer_gap_print_r_nan.php
 *   php bin/jit.php test/repro/maintainer_gap_print_r_nan.php
 *   php bin/compile.php -l test/repro/maintainer_gap_print_r_nan.php && ./test/repro/maintainer_gap_print_r_nan
 */
echo 'print_r_nan:', print_r(NAN, true), "\n";
echo 'print_r_inf:', print_r(INF, true), "\n";
echo 'print_r_neg_inf:', print_r(-INF, true), "\n";
