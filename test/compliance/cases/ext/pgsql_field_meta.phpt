--TEST--
ext/pgsql field metadata APIs (#20703)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
foreach (['pg_field_name', 'pg_field_num', 'pg_field_type', 'pg_field_size', 'pg_field_prtlen'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
?>
--EXPECT--
pg_field_name=1
pg_field_num=1
pg_field_type=1
pg_field_size=1
pg_field_prtlen=1
