--TEST--
socket_bind()/socket_listen() thin AOT via create_pair (#31241)
--FILE--
<?php
// AF_UNIX create_pair is the reliable AOT socket source (#27423); Zend allows bind+listen on it.
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    return;
}
@unlink('/tmp/phpc-31241-bind.sock');
$bound = @socket_bind($pair[0], '/tmp/phpc-31241-bind.sock');
var_dump($bound);
$listening = @socket_listen($pair[0], 1);
var_dump($listening);
echo "bind_listen_ok\n";
socket_close($pair[0]);
socket_close($pair[1]);
@unlink('/tmp/phpc-31241-bind.sock');
?>
--EXPECT--
bool(true)
bool(true)
bind_listen_ok
