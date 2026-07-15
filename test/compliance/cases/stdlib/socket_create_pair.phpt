--TEST--
socket_create_pair() AF_UNIX stream round-trip (#6563)
--SKIPIF--
<?php
if (!function_exists('socket_create_pair')) {
    die('skip socket_create_pair unavailable');
}
?>
--FILE--
<?php
var_export(function_exists('socket_create_pair'));
echo "\n";
$pair = [];
$ok = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
var_export($ok);
echo "\n";
if ($ok) {
    socket_write($pair[0], 'hi', 2);
    echo socket_read($pair[1], 2), "\n";
}
$pair2 = [];
$bad = @socket_create_pair(AF_INET, SOCK_STREAM, 0, $pair2);
var_export($bad);
echo "\n";
?>
--EXPECT--
true
true
hi
false
