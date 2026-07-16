<?php
// Issue #6248 — socket_sendto/recvfrom/getsockname UDP loopback
echo 'sendto=', (int) function_exists('socket_sendto'), "\n";
echo 'recvfrom=', (int) function_exists('socket_recvfrom'), "\n";
echo 'getsockname=', (int) function_exists('socket_getsockname'), "\n";
echo 'getpeername=', (int) function_exists('socket_getpeername'), "\n";

$s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if (!socket_bind($s, '127.0.0.1', 0)) {
    echo "bind_fail\n";
    exit(1);
}
$addr = '';
$port = 0;
if (!socket_getsockname($s, $addr, $port)) {
    echo "getsockname_fail\n";
    exit(1);
}
echo 'bound=', $addr, ' port_ok=', ($port > 0 ? '1' : '0'), "\n";

$c = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
$n = socket_sendto($c, 'hi', 2, 0, '127.0.0.1', $port);
echo 'sent=', $n, "\n";

$buf = '';
$from = '';
$fport = 0;
$got = socket_recvfrom($s, $buf, 16, 0, $from, $fport);
echo 'got=', $got, ' buf=', $buf, ' from=', $from, ' fport_ok=', ($fport > 0 ? '1' : '0'), "\n";

socket_close($c);
socket_close($s);
echo "ok\n";
