<?php

declare(strict_types=1);

/**
 * Maintainer repro: get_defined_vars() after extract() must include imported names (#4517).
 *
 * php-src: ext/standard/basic_functions.c — zend_get_defined_vars
 */

function probe(): void
{
    extract(['a' => 1, 'b' => 2]);
    $vars = get_defined_vars();
    if (!isset($vars['a']) || !isset($vars['b'])) {
        echo "fail: missing extract imports\n";
        exit(1);
    }
    if (1 !== $vars['a'] || 2 !== $vars['b']) {
        echo 'fail: wrong values: ', var_export($vars, true), "\n";
        exit(1);
    }
}

probe();
echo "ok\n";
