<?php
// Issue #6533 — socket_shutdown + getopt/setopt aliases
echo 'shutdown=', (int) function_exists('socket_shutdown'), "\n";
echo 'getopt=', (int) function_exists('socket_getopt'), "\n";
echo 'setopt=', (int) function_exists('socket_setopt'), "\n";

$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    exit(1);
}
echo 'setopt=', (int) socket_setopt($pair[0], SOL_SOCKET, SO_REUSEADDR, 1), "\n";
echo 'getopt=', (int) socket_getopt($pair[0], SOL_SOCKET, SO_REUSEADDR), "\n";
$how = defined('SHUT_RDWR') ? SHUT_RDWR : 2;
echo 'shutdown=', (int) socket_shutdown($pair[0], $how), "\n";
socket_close($pair[0]);
socket_close($pair[1]);
echo "ok\n";
