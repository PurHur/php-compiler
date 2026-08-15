<?php
// Issue #31294 — socket_send/recv NestedJIT with by-ref recv buffer
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    exit(1);
}
$n = socket_send($pair[0], 'hi', 2, 0);
if (false === $n || 2 !== $n) {
    echo "send_fail n=", var_export($n, true), "\n";
    exit(1);
}
echo "sent=", $n, "\n";
$buf = '';
$got = socket_recv($pair[1], $buf, 16, 0);
if (false === $got || 2 !== $got || 'hi' !== $buf) {
    echo "recv_fail got=", var_export($got, true), " buf=", var_export($buf, true), "\n";
    exit(1);
}
echo "got=", $got, " buf=", $buf, "\n";
socket_close($pair[0]);
socket_close($pair[1]);
echo "ok\n";
