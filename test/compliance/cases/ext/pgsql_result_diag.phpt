--TEST--
ext/pgsql pg_result_error/error_field/last_oid + PGSQL_DIAG_* (#20720)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
foreach (['pg_result_error', 'pg_result_error_field', 'pg_last_oid'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
foreach (['PGSQL_DIAG_SEVERITY', 'PGSQL_DIAG_SQLSTATE', 'PGSQL_DIAG_MESSAGE_PRIMARY'] as $c) {
    echo $c, '=', (int) defined($c), "\n";
}
echo 'SEVERITY=', (int) constant('PGSQL_DIAG_SEVERITY'), "\n";
echo 'SQLSTATE=', (int) constant('PGSQL_DIAG_SQLSTATE'), "\n";
?>
--EXPECT--
pg_result_error=1
pg_result_error_field=1
pg_last_oid=1
PGSQL_DIAG_SEVERITY=1
PGSQL_DIAG_SQLSTATE=1
PGSQL_DIAG_MESSAGE_PRIMARY=1
SEVERITY=83
SQLSTATE=67
