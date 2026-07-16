<?php

declare(strict_types=1);

/**
 * Repro for #6333 — socket_cmsg_space / sendmsg / recvmsg.
 */
foreach (['socket_cmsg_space', 'socket_sendmsg', 'socket_recvmsg'] as $fn) {
    echo $fn, ': ', function_exists($fn) ? 'yes' : 'no', "\n";
}
echo 'cmsg=', socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS), "\n";
echo 'cmsg1=', socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1), "\n";

socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $socks);
$a = $socks[0];
$b = $socks[1];
$n = socket_sendmsg($a, ['iov' => ['hello']], 0);
echo 'send=', $n, "\n";
$msg = [
    'buffer_size' => 64,
    'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS),
];
$rn = socket_recvmsg($b, $msg, 0);
echo 'recv=', $rn, "\n";
echo 'iov=', $msg['iov'][0] ?? '', "\n";
socket_close($a);
socket_close($b);
