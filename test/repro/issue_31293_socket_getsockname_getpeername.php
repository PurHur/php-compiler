<?php
// Issue #31293 — AOT NestedJIT getsockname/getpeername with by-ref outs (AF_INET).
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!$s) {
    echo "create_fail\n";
    exit(1);
}
if (!socket_bind($s, '127.0.0.1', 0)) {
    echo "bind_fail\n";
    exit(1);
}
if (!socket_listen($s, 1)) {
    echo "listen_fail\n";
    exit(1);
}
$addr = '';
$port = 0;
if (!socket_getsockname($s, $addr, $port)) {
    echo "getsockname_fail\n";
    exit(1);
}
echo 'bound=', $addr, ' port_ok=', ($port > 0 ? '1' : '0'), "\n";

$c = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!$c || !socket_connect($c, '127.0.0.1', $port)) {
    echo "connect_fail\n";
    exit(1);
}
$paddr = '';
$pport = 0;
if (!socket_getpeername($c, $paddr, $pport)) {
    echo "getpeername_fail\n";
    exit(1);
}
echo 'peer=', $paddr, ' peer_port_ok=', ($pport > 0 ? '1' : '0'), "\n";
@socket_accept($s); // drain pending connection
socket_close($c);
socket_close($s);
echo "ok\n";
