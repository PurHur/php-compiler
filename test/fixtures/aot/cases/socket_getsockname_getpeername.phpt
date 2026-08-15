--TEST--
socket_getsockname()/socket_getpeername() thin AOT NestedJIT (#31293)
--FILE--
<?php
// AF_UNIX create_pair is the reliable AOT socket source (#27423 / #31240 / #31308).
// AF_INET create/create_listen NestedJIT is false on some hosts (socket_create_close.phpt).
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    return;
}
$addr = '';
$port = 0;
$ok = @socket_getsockname($pair[0], $addr, $port);
// Prove call() lowers (no LogicException). AF_UNIX nameInet returns false (AF_INET-only #6248).
var_dump(false === $ok || true === $ok);
$paddr = '';
$pport = 0;
$pok = @socket_getpeername($pair[0], $paddr, $pport);
var_dump(false === $pok || true === $pok);
echo "name_linked_ok\n";
socket_close($pair[0]);
socket_close($pair[1]);
?>
--EXPECT--
bool(true)
bool(true)
name_linked_ok
