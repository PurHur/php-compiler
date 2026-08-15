--TEST--
socket_shutdown thin AOT NestedJIT (#31292)
--FILE--
<?php
$pair = [];
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
    echo "pair_fail\n";
    return;
}
// SHUT_RDWR = 2
echo 'shutdown=', (int) socket_shutdown($pair[0], 2), "\n";
echo 'default=', (int) socket_shutdown($pair[1]), "\n";
socket_close($pair[0]);
socket_close($pair[1]);
echo "shutdown_linked_ok\n";
?>
--EXPECT--
shutdown=1
default=1
shutdown_linked_ok
