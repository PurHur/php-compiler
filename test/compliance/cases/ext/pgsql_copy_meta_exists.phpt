--TEST--
ext/pgsql pg_copy_*/meta/convert/field_* exist when libpq advertises (#20629)
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
