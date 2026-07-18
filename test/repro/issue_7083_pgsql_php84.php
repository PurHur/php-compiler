<?php
// Repro for #7083 — PHP 8.4 pgsql helpers
declare(strict_types=1);

foreach ([
    'pg_change_password',
    'pg_jit',
    'pg_put_copy_data',
    'pg_put_copy_end',
    'pg_result_memory_size',
    'pg_set_chunked_rows_size',
    'pg_socket_poll',
] as $fn) {
    echo $fn, ': ', function_exists($fn) ? 'yes' : 'no', "\n";
}

$host = getenv('PHP_COMPILER_PGSQL_HOST');
$port = getenv('PHP_COMPILER_PGSQL_PORT') ?: '5432';
if (false === $host || '' === $host) {
    echo "skip_live\n";
    exit(0);
}
$conn = pg_connect("host={$host} port={$port} dbname=test user=test password=test");
if (false === $conn) {
    echo 'connect_fail=', pg_last_error(), "\n";
    exit(1);
}
$res = pg_query($conn, 'SELECT 1 AS n');
$mem = pg_result_memory_size($res);
echo 'mem=', $mem, "\n";
echo 'mem_ok=', (int) ($mem >= 0), "\n";
$jit = pg_jit($conn);
echo 'jit_keys=', implode(',', array_keys($jit)), "\n";
pg_close($conn);
echo "ok\n";
