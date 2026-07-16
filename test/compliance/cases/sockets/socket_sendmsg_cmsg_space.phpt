--TEST--
stdlib socket_cmsg_space/sendmsg/recvmsg iov round-trip (#6333, ext/sockets/sendrecvmsg.c)
--FILE--
<?php
declare(strict_types=1);

echo 'cmsg_fn=', (int) function_exists('socket_cmsg_space'), "\n";
echo 'send_fn=', (int) function_exists('socket_sendmsg'), "\n";
echo 'recv_fn=', (int) function_exists('socket_recvmsg'), "\n";
echo 'cmsg0=', socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS), "\n";
echo 'cmsg1=', socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1), "\n";
echo 'cmsg2=', socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 2), "\n";

$ok = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $socks);
echo 'pair=', (int) $ok, "\n";
$a = $socks[0];
$b = $socks[1];
$n = socket_sendmsg($a, ['iov' => ['ping', 'pong']], 0);
echo 'send=', $n, "\n";
$msg = [
    'buffer_size' => 64,
    'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS),
];
$rn = socket_recvmsg($b, $msg, 0);
echo 'recv=', $rn, "\n";
echo 'payload=', $msg['iov'][0] ?? '', "\n";
echo 'flags=', $msg['flags'] ?? -1, "\n";
socket_close($a);
socket_close($b);
echo "done\n";
--EXPECT--
cmsg_fn=1
send_fn=1
recv_fn=1
cmsg0=16
cmsg1=24
cmsg2=24
pair=1
send=8
recv=8
payload=pingpong
flags=0
done
