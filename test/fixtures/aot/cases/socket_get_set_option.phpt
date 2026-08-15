--TEST--
socket_get_option()/socket_set_option() thin AOT NestedJIT (#31295)
--FILE--
<?php
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    return;
}
$set = @socket_set_option($pair[0], SOL_SOCKET, SO_REUSEADDR, 1);
var_dump(false === $set || true === $set);
$got = @socket_get_option($pair[0], SOL_SOCKET, SO_REUSEADDR);
var_dump(false === $got || is_int($got));
echo "option_linked_ok\n";
socket_close($pair[0]);
socket_close($pair[1]);
?>
--EXPECT--
bool(true)
bool(true)
option_linked_ok
