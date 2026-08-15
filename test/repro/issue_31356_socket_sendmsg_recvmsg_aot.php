<?php

declare(strict_types=1);

/**
 * Repro #31356 — thin AOT must lower socket_sendmsg()/socket_recvmsg().
 * AF_UNIX create_pair + iov-only round-trip (peer #31332 / #6333).
 * php-src: ext/sockets/sendrecvmsg.c PHP_FUNCTION(socket_sendmsg|socket_recvmsg)
 */
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    fwrite(STDERR, "pair_fail\n");
    exit(1);
}
$a = $pair[0];
$b = $pair[1];
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
echo "sendmsg_recvmsg_aot_ok\n";
