--TEST--
socket_getsockname()/socket_getpeername() thin AOT (#31327)
--FILE--
<?php
// AF_UNIX create_pair is the reliable AOT socket source (#27423 / #31308).
// AF_INET create_listen may be false under thin AOT (peer #31242).
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    return;
}
$addr = '';
$port = 0;
$r = @socket_getsockname($pair[0], $addr, $port);
var_dump(is_bool($r));
$paddr = '';
$pport = 0;
$r2 = @socket_getpeername($pair[0], $paddr, $pport);
var_dump(is_bool($r2));
echo "getsockname_linked_ok\n";
socket_close($pair[0]);
socket_close($pair[1]);
?>
--EXPECT--
bool(true)
bool(true)
getsockname_linked_ok
