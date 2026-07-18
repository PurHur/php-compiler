--TEST--
ext/pgsql pg_set_error_verbosity + PGSQL_ERRORS_* (#20660)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
echo 'fn=', (int) function_exists('pg_set_error_verbosity'), "\n";
foreach (['PGSQL_ERRORS_TERSE', 'PGSQL_ERRORS_DEFAULT', 'PGSQL_ERRORS_VERBOSE', 'PGSQL_ERRORS_SQLSTATE'] as $c) {
    echo $c, '=', (int) defined($c), "\n";
}
echo 'TERSE=', (int) constant('PGSQL_ERRORS_TERSE'), "\n";
echo 'DEFAULT=', (int) constant('PGSQL_ERRORS_DEFAULT'), "\n";
echo 'VERBOSE=', (int) constant('PGSQL_ERRORS_VERBOSE'), "\n";
echo 'SQLSTATE=', (int) constant('PGSQL_ERRORS_SQLSTATE'), "\n";
try {
    pg_set_error_verbosity();
    echo "argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "argc=ok\n";
}
?>
--EXPECT--
fn=1
PGSQL_ERRORS_TERSE=1
PGSQL_ERRORS_DEFAULT=1
PGSQL_ERRORS_VERBOSE=1
PGSQL_ERRORS_SQLSTATE=1
TERSE=0
DEFAULT=1
VERBOSE=2
SQLSTATE=3
argc=ok
