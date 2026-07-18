--TEST--
ext/pgsql pg_connect/query/fetch_assoc v1 (#3741)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) die('skip no ffi');
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
$host = getenv('PHP_COMPILER_PGSQL_HOST');
$port = getenv('PHP_COMPILER_PGSQL_PORT') ?: '5432';
if (false === $host || '' === $host) die('skip no PHP_COMPILER_PGSQL_HOST');
$conn = @pg_connect("host={$host} port={$port} dbname=test user=test password=test connect_timeout=2");
if (false === $conn) die('skip postgres unreachable');
pg_close($conn);
?>
--FILE--
<?php
declare(strict_types=1);
$host = getenv('PHP_COMPILER_PGSQL_HOST');
$port = getenv('PHP_COMPILER_PGSQL_PORT') ?: '5432';
$conn = pg_connect("host={$host} port={$port} dbname=test user=test password=test");
echo 'connected=', (int) (false !== $conn), "\n";
$res = pg_query($conn, 'SELECT 1 AS n');
echo 'num=', pg_num_rows($res), "\n";
$row = pg_fetch_assoc($res);
echo 'n=', $row['n'], "\n";
$row2 = pg_fetch_row(pg_query($conn, 'SELECT 2 AS m'));
echo 'm=', $row2[0], "\n";
pg_close($conn);
echo "ok\n";
?>
--EXPECT--
connected=1
num=1
n=1
m=2
ok
