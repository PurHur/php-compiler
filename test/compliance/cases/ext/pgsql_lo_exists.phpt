--TEST--
ext/pgsql pg_lo_* + PgSql\Lob exist when libpq advertises (#20587)
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
    'pg_lo_create',
    'pg_lo_open',
    'pg_lo_close',
    'pg_lo_read',
    'pg_lo_write',
    'pg_lo_read_all',
    'pg_lo_seek',
    'pg_lo_tell',
    'pg_lo_truncate',
    'pg_lo_import',
    'pg_lo_export',
    'pg_lo_unlink',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
echo 'Lob=', (int) class_exists('PgSql\\Lob'), "\n";
?>
--EXPECT--
pg_lo_create=1
pg_lo_open=1
pg_lo_close=1
pg_lo_read=1
pg_lo_write=1
pg_lo_read_all=1
pg_lo_seek=1
pg_lo_tell=1
pg_lo_truncate=1
pg_lo_import=1
pg_lo_export=1
pg_lo_unlink=1
Lob=1
