--TEST--
socket_sendmsg/recvmsg thin AOT NestedJIT (#31356)
--FILE--
<?php
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    return;
}
$n = socket_sendmsg($pair[0], ['iov' => ['hello']], 0);
echo 'send=', $n, "\n";
$msg = [
    'buffer_size' => 64,
    'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS),
];
$rn = socket_recvmsg($pair[1], $msg, 0);
echo 'recv=', $rn, "\n";
echo 'iov=', $msg['iov'][0] ?? '', "\n";
echo "sendmsg_recvmsg_linked_ok\n";
socket_close($pair[0]);
socket_close($pair[1]);
?>
--EXPECT--
send=5
recv=5
iov=hello
sendmsg_recvmsg_linked_ok
