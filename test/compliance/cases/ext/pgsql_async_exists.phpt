--TEST--
ext/pgsql async send/result/cancel/notify APIs exist when libpq advertises (#20636, #20681)
--SKIPIF--
<?php
if (!function_exists('pg_connect')) die('skip no pg_connect (libpq FFI)');
?>
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'pg_socket', 'pg_consume_input', 'pg_flush', 'pg_socket_poll',
    'pg_send_query', 'pg_send_query_params', 'pg_send_prepare', 'pg_send_execute',
    'pg_get_result', 'pg_cancel_query', 'pg_get_notify',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
?>
--EXPECT--
pg_socket=1
pg_consume_input=1
pg_flush=1
pg_socket_poll=1
pg_send_query=1
pg_send_query_params=1
pg_send_prepare=1
pg_send_execute=1
pg_get_result=1
pg_cancel_query=1
pg_get_notify=1
