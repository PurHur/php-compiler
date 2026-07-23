--TEST--
ext/pgsql PHP 8.4 helpers function_exists + result memory (#7083, #22543)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
// Soft-exit: BaseTest ignores --SKIPIF-- (see tidy_repair_host.phpt).
if (!function_exists('pg_connect') || !function_exists('pg_result_memory_size')) {
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
$host = getenv('PHP_COMPILER_PGSQL_HOST');
if (false === $host || '' === $host) {
    echo "skip_live\n";
    exit(0);
}
$port = getenv('PHP_COMPILER_PGSQL_PORT') ?: '5432';
$conn = @pg_connect("host={$host} port={$port} dbname=test user=test password=test connect_timeout=2");
if (false === $conn) {
    echo "skip_unreachable\n";
    exit(0);
}
$res = pg_query($conn, 'SELECT 1 AS n');
echo 'mem_nonneg=', (int) (pg_result_memory_size($res) >= 0), "\n";
$jit = pg_jit($conn);
echo 'has_jit=', (int) array_key_exists('jit', $jit), "\n";
try {
    pg_result_memory_size(false);
    echo "type_ok\n";
} catch (TypeError $e) {
    echo "type_err\n";
}
pg_close($conn);
echo "ok\n";
?>
--EXPECTF--
pg_change_password=1
pg_jit=1
pg_put_copy_data=1
pg_put_copy_end=1
pg_result_memory_size=1
pg_set_chunked_rows_size=1
pg_socket_poll=1
%a
