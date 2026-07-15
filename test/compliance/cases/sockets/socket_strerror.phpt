--TEST--
stdlib socket_strerror/last_error/clear_error (#6227, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['socket_strerror', 'socket_last_error', 'socket_clear_error'] as $fn) {
    echo $fn, ':', (int) function_exists($fn), "\n";
}
echo socket_strerror(0), "\n";
echo socket_strerror(111), "\n";

$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
@socket_connect($s, '127.0.0.1', 1);
echo 'sock=', socket_last_error($s), "\n";
echo 'global=', socket_last_error(), "\n";
socket_clear_error($s);
echo 'cleared_sock=', socket_last_error($s), "\n";
echo 'global_after=', socket_last_error(), "\n";
socket_clear_error();
echo 'cleared_global=', socket_last_error(), "\n";
socket_close($s);
--EXPECT--
socket_strerror:1
socket_last_error:1
socket_clear_error:1
Success
Connection refused
sock=111
global=111
cleared_sock=0
global_after=111
cleared_global=0
