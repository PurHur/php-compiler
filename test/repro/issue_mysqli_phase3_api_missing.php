<?php

declare(strict_types=1);

/** Repro #21791 — mysqli phase-3 API registration. */
$funcs = [
    'mysqli_real_connect',
    'mysqli_options',
    'mysqli_set_charset',
    'mysqli_multi_query',
    'mysqli_next_result',
    'mysqli_store_result',
    'mysqli_info',
    'mysqli_stat',
];
foreach ($funcs as $fn) {
    echo $fn, ':', function_exists($fn) ? 'yes' : 'no', "\n";
}
