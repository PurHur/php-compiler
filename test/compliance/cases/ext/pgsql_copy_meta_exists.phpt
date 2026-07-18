--TEST--
ext/pgsql pg_copy_*/meta/convert/field_* exist when libpq advertises (#20629)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'pg_copy_to',
    'pg_copy_from',
    'pg_meta_data',
    'pg_convert',
    'pg_field_table',
    'pg_field_type_oid',
    'pg_field_is_null',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
?>
--EXPECT--
pg_copy_to=1
pg_copy_from=1
pg_meta_data=1
pg_convert=1
pg_field_table=1
pg_field_type_oid=1
pg_field_is_null=1
