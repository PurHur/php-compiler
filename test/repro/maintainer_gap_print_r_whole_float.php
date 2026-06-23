<?php

declare(strict_types=1);

/**
 * Issue #10933 — print_r() whole-number floats display as integers.
 *
 * php-src: ext/standard/print.c — php_print_r / zend_print_zval double branch.
 *
 * Verify:
 *   php bin/vm.php test/repro/maintainer_gap_print_r_whole_float.php
 *   php bin/jit.php test/repro/maintainer_gap_print_r_whole_float.php
 *   php bin/compile.php -l test/repro/maintainer_gap_print_r_whole_float.php && ./test/repro/maintainer_gap_print_r_whole_float
 */
echo print_r(1.0, true), "\n";
echo print_r(2.0, true), "\n";
echo print_r(1.5, true), "\n";
