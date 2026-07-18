--TEST--
ext/pgsql pg_insert/update/delete/select + DML constants exist (#20637)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
foreach (['pg_insert', 'pg_update', 'pg_delete', 'pg_select'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
foreach (['PGSQL_DML_EXEC', 'PGSQL_DML_STRING', 'PGSQL_ASSOC'] as $c) {
    echo $c, '=', (int) defined($c), "\n";
}
echo 'EXEC=', (int) constant('PGSQL_DML_EXEC'), "\n";
?>
--EXPECT--
pg_insert=1
pg_update=1
pg_delete=1
pg_select=1
PGSQL_DML_EXEC=1
PGSQL_DML_STRING=1
PGSQL_ASSOC=1
EXEC=512
