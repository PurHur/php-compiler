<?php
/**
 * Repro for #21896 — pg_connect_poll + PGSQL_CONNECT_ASYNC / POLLING_* constants.
 */
declare(strict_types=1);

if (!function_exists('pg_connect')) {
    echo "SKIP no pg_connect\n";
    exit(0);
}

foreach (['pg_connect_poll', 'pg_socket', 'pg_consume_input'] as $f) {
    echo $f, '=', var_export(function_exists($f), true), "\n";
}

foreach ([
    'PGSQL_CONNECT_ASYNC',
    'PGSQL_CONNECT_FORCE_NEW',
    'PGSQL_POLLING_FAILED',
    'PGSQL_POLLING_READING',
    'PGSQL_POLLING_WRITING',
    'PGSQL_POLLING_OK',
    'PGSQL_POLLING_ACTIVE',
] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'UNDEF', "\n";
}

$conn = @pg_connect('host=127.0.0.1 port=1 dbname=nope user=nope password=nope', PGSQL_CONNECT_ASYNC);
if (false === $conn) {
    echo "async_start=false\n";
} else {
    echo "async_start=ok\n";
    $poll = pg_connect_poll($conn);
    echo 'poll0=', (string) $poll, "\n";
    $n = 0;
    while ($poll !== PGSQL_POLLING_OK && $poll !== PGSQL_POLLING_FAILED && $n < 64) {
        usleep(10000);
        $poll = pg_connect_poll($conn);
        $n++;
    }
    echo 'poll_final=', (string) $poll, "\n";
    echo 'failed=', (int) ($poll === PGSQL_POLLING_FAILED), "\n";
    @pg_close($conn);
}
