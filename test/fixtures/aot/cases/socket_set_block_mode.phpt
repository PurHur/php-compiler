--TEST--
socket_set_nonblock()/socket_set_block() thin AOT (#31285)
--FILE--
<?php
$pair = [];
$ok = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
var_dump($ok);
if ($ok) {
    var_dump(socket_set_nonblock($pair[0]));
    var_dump(socket_set_block($pair[0]));
    socket_close($pair[0]);
    socket_close($pair[1]);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
