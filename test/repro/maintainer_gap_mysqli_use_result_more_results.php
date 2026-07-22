<?php

declare(strict_types=1);

/** Repro #22184 — mysqli use_result / more_results registration. */
$funcs = [
    'mysqli_use_result',
    'mysqli_more_results',
    'mysqli_stmt_more_results',
    'mysqli_stmt_next_result',
    'mysqli_multi_query',
    'mysqli_next_result',
    'mysqli_store_result',
];
foreach ($funcs as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}
