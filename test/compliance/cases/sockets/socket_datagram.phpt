--TEST--
stdlib socket_sendto/recvfrom/getsockname UDP loopback (#6248, ext/sockets/sockets.c)
--FILE--
<?php
echo 'sendto=', (int) function_exists('socket_sendto'), "\n";
echo 'recvfrom=', (int) function_exists('socket_recvfrom'), "\n";
echo 'getsockname=', (int) function_exists('socket_getsockname'), "\n";
echo 'getpeername=', (int) function_exists('socket_getpeername'), "\n";

$s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if (!socket_bind($s, '127.0.0.1', 0)) {
    fwrite(STDERR, "bind fail\n");
    exit(1);
}
$addr = '';
$port = 0;
if (!socket_getsockname($s, $addr, $port)) {
    fwrite(STDERR, "getsockname fail\n");
    exit(1);
}
echo 'bound=', $addr, "\n";
echo 'port_ok=', ($port > 0 ? '1' : '0'), "\n";

$c = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
$n = socket_sendto($c, 'ping', 4, 0, '127.0.0.1', $port);
echo 'sent=', $n, "\n";

$buf = '';
$from = '';
$fport = 0;
$got = socket_recvfrom($s, $buf, 16, 0, $from, $fport);
echo 'got=', $got, ' buf=', $buf, ' from=', $from, "\n";
echo 'fport_ok=', ($fport > 0 ? '1' : '0'), "\n";

socket_close($c);
socket_close($s);
echo "done\n";
--EXPECT--
sendto=1
recvfrom=1
getsockname=1
getpeername=1
bound=127.0.0.1
port_ok=1
sent=4
got=4 buf=ping from=127.0.0.1
fport_ok=1
done
