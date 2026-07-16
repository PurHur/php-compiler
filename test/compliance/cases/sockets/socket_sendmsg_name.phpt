--TEST--
stdlib socket_sendmsg/recvmsg msg_name AF_INET datagram (#19408, ext/sockets/sendrecvmsg.c)
--FILE--
<?php
declare(strict_types=1);

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
$n = socket_sendmsg($c, [
    'name' => ['addr' => '127.0.0.1', 'port' => $port],
    'iov' => ['hi'],
], 0);
echo 'sent=', $n, "\n";

$msg = [
    'buffer_size' => 64,
    'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS),
];
$rn = socket_recvmsg($s, $msg, 0);
echo 'recv=', $rn, "\n";
echo 'payload=', $msg['iov'][0] ?? '', "\n";
echo 'from_addr=', $msg['name']['addr'] ?? '', "\n";
echo 'from_port_ok=', (isset($msg['name']['port']) && $msg['name']['port'] > 0 ? '1' : '0'), "\n";

socket_close($c);
socket_close($s);
echo "done\n";
--EXPECT--
bound=127.0.0.1
port_ok=1
sent=2
recv=2
payload=hi
from_addr=127.0.0.1
from_port_ok=1
done
