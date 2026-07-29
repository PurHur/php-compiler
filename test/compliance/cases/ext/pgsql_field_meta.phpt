--TEST--
ext/pgsql field metadata APIs (#20703)
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
