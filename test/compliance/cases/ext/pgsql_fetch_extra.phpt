--TEST--
ext/pgsql pg_fetch_array/object/result + free/seek (#20704)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
foreach (['pg_fetch_array', 'pg_fetch_object', 'pg_fetch_result', 'pg_free_result', 'pg_result_seek'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
?>
--EXPECT--
pg_fetch_array=1
pg_fetch_object=1
pg_fetch_result=1
pg_free_result=1
pg_result_seek=1
