<?php
/**
 * Repro for #20636 — pg_socket / pg_consume_input / pg_flush registration when libpq advertises.
 */
declare(strict_types=1);

if (!function_exists('pg_connect')) {
    echo "SKIP no pg_connect\n";
    exit(0);
}

// pg_socket_poll is PHP 8.4-only (#22543); covered by issue_22543 / pgsql_php84_* tests.
foreach (['pg_socket', 'pg_consume_input', 'pg_flush'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', PHP_EOL;
}
