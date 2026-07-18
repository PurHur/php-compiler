--TEST--
ext/pgsql pg_copy_*/put_line/end_copy/meta/convert/field_* exist when libpq advertises (#20629, #20673)
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
    'pg_put_line',
    'pg_end_copy',
    'pg_meta_data',
    'pg_convert',
    'pg_field_table',
    'pg_field_type_oid',
    'pg_field_is_null',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
try {
    pg_put_line();
    echo "put_line_argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "put_line_argc=ok\n";
}
try {
    pg_end_copy(1, 2);
    echo "end_copy_argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "end_copy_argc=ok\n";
}
?>
--EXPECT--
pg_copy_to=1
pg_copy_from=1
pg_put_line=1
pg_end_copy=1
pg_meta_data=1
pg_convert=1
pg_field_table=1
pg_field_type_oid=1
pg_field_is_null=1
put_line_argc=ok
end_copy_argc=ok
