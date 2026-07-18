--TEST--
ext/pgsql pg_socket/consume_input/flush exist when libpq advertises (#20636)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
foreach (['pg_socket', 'pg_consume_input', 'pg_flush', 'pg_socket_poll'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
?>
--EXPECT--
pg_socket=1
pg_consume_input=1
pg_flush=1
pg_socket_poll=1
