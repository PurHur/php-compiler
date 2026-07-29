--TEST--
ext/pgsql pg_result_status/pg_get_pid + PGSQL_STATUS_* (#20702)
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
foreach (['pg_result_status', 'pg_get_pid'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
foreach (['PGSQL_STATUS_LONG', 'PGSQL_STATUS_STRING'] as $c) {
    echo $c, '=', (int) defined($c), "\n";
}
echo 'LONG=', (int) constant('PGSQL_STATUS_LONG'), "\n";
echo 'STRING=', (int) constant('PGSQL_STATUS_STRING'), "\n";
?>
--EXPECT--
pg_result_status=1
pg_get_pid=1
PGSQL_STATUS_LONG=1
PGSQL_STATUS_STRING=1
LONG=1
STRING=2
