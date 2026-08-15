--TEST--
socket_connect() thin AOT linked via create_pair (#31240)
--FILE--
<?php
// Prove call() lowers (no LogicException at compile). AF_UNIX pair is reliable under AOT (#27423);
// AF_INET create/create_pair NestedJIT is false on some hosts — null-port ValueError stays VM (#30339).
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    return;
}
$r = @socket_connect($pair[0], '/tmp/phpc-31240-connect.sock');
var_dump(is_bool($r));
echo "connect_linked_ok\n";
socket_close($pair[0]);
socket_close($pair[1]);
?>
--EXPECT--
bool(true)
connect_linked_ok
