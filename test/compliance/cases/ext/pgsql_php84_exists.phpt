--TEST--
ext/pgsql PHP 8.4 helpers exist without live Postgres (#7083, #22543)
--ENV--
PHP_COMPILER_ENABLE_PGSQL=1
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
// Soft-exit: BaseTest ignores --SKIPIF--.
if (!function_exists('pg_connect')) {
    echo "skip\n";
    exit(0);
}
foreach ([
    'pg_change_password',
    'pg_jit',
    'pg_put_copy_data',
    'pg_put_copy_end',
    'pg_result_memory_size',
    'pg_set_chunked_rows_size',
    'pg_socket_poll',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
?>
--EXPECT--
pg_change_password=1
pg_jit=1
pg_put_copy_data=1
pg_put_copy_end=1
pg_result_memory_size=1
pg_set_chunked_rows_size=1
pg_socket_poll=1
