--TEST--
ext/pgsql pg_pconnect exists + argc + fail (#22218)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
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
