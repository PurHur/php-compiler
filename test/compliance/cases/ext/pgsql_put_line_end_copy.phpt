--TEST--
ext/pgsql pg_put_line()/pg_end_copy() registration (#20673)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
echo 'pg_put_line=', (int) function_exists('pg_put_line'), "\n";
echo 'pg_end_copy=', (int) function_exists('pg_end_copy'), "\n";
try {
    pg_put_line();
    echo "put_argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "put_argc=ok\n";
}
try {
    pg_end_copy(1, 2);
    echo "end_argc=fail\n";
} catch (ArgumentCountError $e) {
    echo "end_argc=ok\n";
}
?>
--EXPECT--
pg_put_line=1
pg_end_copy=1
put_argc=ok
end_argc=ok
