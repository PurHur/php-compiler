<?php

declare(strict_types=1);

/** Repro #22163 — mysqli_poll / mysqli_reap_async_query registration. */
$funcs = [
    'mysqli_poll',
    'mysqli_reap_async_query',
    'mysqli_multi_query',
    'mysqli_next_result',
];
foreach ($funcs as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'NO', "\n";
}
echo 'method_poll=', method_exists('mysqli', 'poll') ? 'yes' : 'NO', "\n";
echo 'method_reap=', method_exists('mysqli', 'reap_async_query') ? 'yes' : 'NO', "\n";
echo 'MYSQLI_ASYNC=', defined('MYSQLI_ASYNC') ? (string) MYSQLI_ASYNC : 'NO', "\n";
