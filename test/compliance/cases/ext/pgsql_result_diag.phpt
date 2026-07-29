--TEST--
ext/pgsql pg_result_error/error_field/last_oid + PGSQL_DIAG_* (#20720)
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
