--TEST--
ext/pgsql pg_set_error_verbosity + PGSQL_ERRORS_* (#20660)
--SKIPIF--
<?php
// Host Zend often lacks ext/pgsql; in-tree path uses PHP_COMPILER_ENABLE_PGSQL (#24994).
if (!extension_loaded('pgsql')) {
    $en = getenv('PHP_COMPILER_ENABLE_PGSQL');
    if (!is_string($en) || '' === trim($en) || in_array(strtolower(trim($en)), ['0', 'false', 'off', 'no'], true)) {
        die('skip pgsql withheld');
    }
}
?>
--ENV--
PHP_COMPILER_ENABLE_PGSQL=1
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
