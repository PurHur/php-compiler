<?php

declare(strict_types=1);

/**
 * Maintainer repro: get_defined_functions(exclude_disabled: true) on reference profile (#16902).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_defined_functions)
 */

$all = get_defined_functions();
$filtered = get_defined_functions(exclude_disabled: true);

if (count($all['internal']) < count($filtered['internal'])) {
    echo "fail: filtered internal count exceeds unfiltered\n";
    exit(1);
}

if (in_array('utf8_encode', $all['internal'], true)
    && !in_array('utf8_encode', $filtered['internal'], true)) {
    echo "fail: utf8_encode wrongly filtered from exclude_disabled list\n";
    exit(1);
}

echo "ok\n";
