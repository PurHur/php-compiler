--TEST--
socket_recvfrom thin AOT NestedJIT (#31332)
--FILE--
<?php
// AF_UNIX create_pair is the reliable AOT socket source (#27423 / #31308).
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    return;
}
@socket_set_nonblock($pair[0]);
$data = '';
$addr = '';
$port = 0;
$n = @socket_recvfrom($pair[0], $data, 16, 0, $addr, $port);
// Prove call() lowers (no LogicException). NestedJIT FFI may return int|false.
var_dump(false === $n || is_int($n));
echo "recvfrom_linked_ok\n";
socket_close($pair[0]);
socket_close($pair[1]);
?>
--EXPECT--
bool(true)
recvfrom_linked_ok
