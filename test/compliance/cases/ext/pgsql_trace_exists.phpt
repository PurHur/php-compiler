--TEST--
ext/pgsql pg_trace/pg_untrace exist when libpq advertises (#20574)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
foreach (['pg_trace', 'pg_untrace'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
echo 'no_default=', (int) @pg_trace('/tmp/phpc_pg_trace_none.log'), "\n";
?>
--EXPECT--
pg_trace=1
pg_untrace=1
no_default=0
