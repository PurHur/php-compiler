<?php
// AOT repro for #31292 — socket_shutdown NestedJIT.
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    fwrite(STDERR, "pair_fail\n");
    exit(1);
}
// SHUT_RDWR = 2 (avoid compile-time constant resolve under thin AOT)
echo 'shutdown=', (int) socket_shutdown($pair[0], 2), "\n";
echo 'default=', (int) socket_shutdown($pair[1]), "\n";
socket_close($pair[0]);
socket_close($pair[1]);
echo "shutdown_aot_ok\n";
