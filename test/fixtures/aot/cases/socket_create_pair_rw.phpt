--TEST--
socket_create_pair()/write/read thin AOT (#27423)
--FILE--
<?php
$pair = [];
$ok = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
var_dump($ok);
if ($ok) {
    socket_write($pair[0], 'hi');
    echo socket_read($pair[1], 2), "\n";
}
?>
--EXPECT--
bool(true)
hi
