--TEST--
ext/pgsql pg_fetch_all_columns exists + argc (#22216)
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
echo 'exists=', (int) function_exists('pg_fetch_all_columns'), "\n";
echo 'sibling=', (int) function_exists('pg_fetch_all'), "\n";
try {
    pg_fetch_all_columns();
    echo "argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "argc=ok\n";
}
?>
--EXPECT--
exists=1
sibling=1
argc=ok
