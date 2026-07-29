--TEST--
ext/pgsql pg_last_notice exists + PGSQL_NOTICE_* (#22217)
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
echo 'exists=', (int) function_exists('pg_last_notice'), "\n";
echo 'sibling=', (int) function_exists('pg_last_error'), "\n";
echo 'LAST=', (int) (defined('PGSQL_NOTICE_LAST') && PGSQL_NOTICE_LAST === 1), "\n";
echo 'ALL=', (int) (defined('PGSQL_NOTICE_ALL') && PGSQL_NOTICE_ALL === 2), "\n";
echo 'CLEAR=', (int) (defined('PGSQL_NOTICE_CLEAR') && PGSQL_NOTICE_CLEAR === 3), "\n";
try {
    pg_last_notice();
    echo "argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "argc=ok\n";
}
?>
--EXPECT--
exists=1
sibling=1
LAST=1
ALL=1
CLEAR=1
argc=ok
