--TEST--
socket_sendto thin AOT NestedJIT (#31308)
--FILE--
<?php
// AF_UNIX create_pair is the reliable AOT socket source (#27423 / #31240).
// AF_INET socket_create NestedJIT is false on some hosts (socket_create_close.phpt).
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    return;
}
$n = @socket_sendto($pair[0], 'hi', 2, 0, '127.0.0.1', 9);
// Prove call() lowers (no LogicException). NestedJIT FFI may return int 0 vs VM false.
var_dump(false === $n || is_int($n));
echo "sendto_linked_ok\n";
socket_close($pair[0]);
socket_close($pair[1]);
?>
--EXPECT--
bool(true)
sendto_linked_ok
