--TEST--
ext/pgsql pg_pconnect exists + argc + fail (#22218)
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
echo 'exists=', (int) function_exists('pg_pconnect'), "\n";
echo 'sibling=', (int) function_exists('pg_connect'), "\n";
try {
    pg_pconnect();
    echo "argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "argc=ok\n";
}
$conn = @pg_pconnect('host=127.0.0.1 port=1 dbname=nope user=nope password=nope connect_timeout=1');
echo 'fail=', (int) (false === $conn), "\n";
?>
--EXPECT--
exists=1
sibling=1
argc=ok
fail=1
