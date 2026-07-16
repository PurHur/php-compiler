--TEST--
stdlib socket_sendmsg SCM_RIGHTS fd pass AF_UNIX (#19407, ext/sockets/sendrecvmsg.c)
--FILE--
<?php
declare(strict_types=1);

socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pairA);
socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pairB);
$sendSock = $pairA[0];
$recvSock = $pairA[1];
$fdToPass = $pairB[0];
$control = [
    ['level' => SOL_SOCKET, 'type' => SCM_RIGHTS, 'data' => [$fdToPass]],
];
$message = ['iov' => ['x'], 'control' => $control];
$n = socket_sendmsg($sendSock, $message, 0);
echo 'send=', $n, "\n";
$msg = [
    'buffer_size' => 64,
    'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS, 1),
];
$rn = socket_recvmsg($recvSock, $msg, 0);
echo 'recv=', $rn, "\n";
echo 'iov=', $msg['iov'][0] ?? '', "\n";
$passed = $msg['control'][0]['data'][0] ?? null;
echo 'passed=', ($passed instanceof Socket ? '1' : '0'), "\n";
socket_close($sendSock);
socket_close($recvSock);
socket_close($fdToPass);
socket_close($pairB[1]);
echo "done\n";
--EXPECT--
send=1
recv=1
iov=x
passed=1
done
