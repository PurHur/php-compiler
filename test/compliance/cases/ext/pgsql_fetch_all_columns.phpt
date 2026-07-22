--TEST--
ext/pgsql pg_fetch_all_columns exists + argc (#22216)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
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
