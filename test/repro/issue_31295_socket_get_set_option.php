<?php
// Issue #31295 — socket_get/set_option NestedJIT (SO_REUSEADDR int path)
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    exit(1);
}
if (!socket_set_option($pair[0], SOL_SOCKET, SO_REUSEADDR, 1)) {
    echo "set_fail\n";
    exit(1);
}
$v = socket_get_option($pair[0], SOL_SOCKET, SO_REUSEADDR);
if (false === $v) {
    echo "get_fail\n";
    exit(1);
}
echo 'reuse=', (int) $v, "\n";
socket_close($pair[0]);
socket_close($pair[1]);
echo "ok\n";
