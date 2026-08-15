--TEST--
socket_create_listen()/socket_accept() thin AOT (#31242)
--FILE--
<?php
$server = @socket_create_listen(0);
var_dump(is_object($server) || $server === false);
echo "create_listen_linked\n";
if (is_object($server)) {
    socket_close($server);
}
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    return;
}
@unlink('/tmp/phpc-31242-accept.sock');
@socket_bind($pair[0], '/tmp/phpc-31242-accept.sock');
@socket_listen($pair[0], 1);
$client = @socket_accept($pair[0]);
var_dump(is_object($client) || $client === false);
echo "accept_linked\n";
if (is_object($client)) {
    socket_close($client);
}
socket_close($pair[0]);
socket_close($pair[1]);
@unlink('/tmp/phpc-31242-accept.sock');
?>
--EXPECT--
bool(true)
create_listen_linked
bool(true)
accept_linked
