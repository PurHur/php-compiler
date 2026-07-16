<?php

declare(strict_types=1);

/**
 * Repro for #19408 — socket_sendmsg/recvmsg msg_name peer addressing.
 */
$s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
socket_bind($s, '127.0.0.1', 0);
$addr = '';
$port = 0;
socket_getsockname($s, $addr, $port);
$c = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
$message = [
    'iov' => ['hi'],
    'name' => ['addr' => '127.0.0.1', 'port' => $port],
];
$n = socket_sendmsg($c, $message, 0);
echo 'send=', $n, "\n";
$msg = [
    'buffer_size' => 64,
    'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS),
];
$rn = socket_recvmsg($s, $msg, 0);
echo 'recv=', $rn, "\n";
echo 'payload=', $msg['iov'][0] ?? '', "\n";
echo 'from=', $msg['name']['addr'] ?? '', ':', $msg['name']['port'] ?? 0, "\n";
socket_close($c);
socket_close($s);
