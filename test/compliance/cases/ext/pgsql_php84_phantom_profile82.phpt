--TEST--
ext/pgsql PHP 8.4 helpers withheld on PROFILE=8.2 (#22543, re-#7083)
--ENV--
PHP_COMPILER_ENABLE_PGSQL=1
PHP_COMPILER_PROFILE=8.2
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
    echo $fn, '=', function_exists($fn) ? 'Y' : 'N', "\n";
}
?>
--EXPECT--
pg_change_password=N
pg_jit=N
pg_put_copy_data=N
pg_put_copy_end=N
pg_result_memory_size=N
pg_set_chunked_rows_size=N
pg_socket_poll=N
