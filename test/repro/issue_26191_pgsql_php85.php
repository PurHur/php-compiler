<?php
// Repro for #26191 — PHP 8.5 pg_close_stmt / pg_service registration
declare(strict_types=1);

echo 'pgsql=', extension_loaded('pgsql') ? 'yes' : 'no', "\n";
foreach (['pg_close_stmt', 'pg_service', 'pg_set_error_context_visibility', 'pg_change_password'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}

$host = getenv('PHP_COMPILER_PGSQL_HOST');
$port = getenv('PHP_COMPILER_PGSQL_PORT') ?: '5432';
if (false === $host || '' === $host) {
    echo "skip_live\n";
    exit(0);
}
$conn = @pg_connect("host={$host} port={$port} dbname=test user=test password=test");
if (false === $conn) {
    echo 'connect_fail=', pg_last_error(), "\n";
    exit(1);
}
$svc = pg_service($conn);
echo 'service_type=', get_debug_type($svc), "\n";
$prep = pg_prepare($conn, 'phpc_close_stmt', 'SELECT 1');
if (false === $prep) {
    echo 'prepare_fail=', pg_last_error($conn), "\n";
    pg_close($conn);
    exit(1);
}
$closed = pg_close_stmt($conn, 'phpc_close_stmt');
if (false === $closed) {
    // libpq < 17: PQclosePrepared absent — function exists, call degrades to false.
    echo 'close_stmt=false_or_unavailable libpq_has=', \PHPCompiler\ext\pgsql\VmPgsqlNative::hasClosePrepared() ? 'yes' : 'no', "\n";
} else {
    echo 'close_stmt=', $closed instanceof PgSql\Result ? 'result' : get_debug_type($closed), "\n";
}
pg_close($conn);
echo "ok\n";
