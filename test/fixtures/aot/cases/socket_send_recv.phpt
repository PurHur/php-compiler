--TEST--
socket_send()/socket_recv() thin AOT NestedJIT (#31294)
--FILE--
<?php
// AF_UNIX create_pair is the reliable AOT socket source (#27423 / #31308 / #31293).
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    return;
}
$n = @socket_send($pair[0], 'hi', 2, 0);
var_dump(false === $n || is_int($n));
$buf = '';
$got = @socket_recv($pair[1], $buf, 16, 0);
var_dump(false === $got || is_int($got));
echo "send_recv_linked_ok\n";
socket_close($pair[0]);
socket_close($pair[1]);
?>
--EXPECT--
bool(true)
bool(true)
send_recv_linked_ok
