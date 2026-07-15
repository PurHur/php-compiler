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
socket_clear_error($s);
echo 'cleared=', socket_last_error($s), "\n";
echo 'global=', socket_last_error(), "\n";
socket_clear_error();
echo 'gclear=', socket_last_error(), "\n";
socket_close($s);
