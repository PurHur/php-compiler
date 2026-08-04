--TEST--
socket_create()/socket_close() AOT live Socket (#27394)
--SKIPIF--
<?php
if (!function_exists('socket_create') || !defined('AF_INET') || !defined('SOL_TCP')) {
    die('skip sockets unavailable');
}
?>
--FILE--
<?php
$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
var_dump($s !== false);
if ($s) {
    socket_close($s);
}
?>
--EXPECT--
bool(true)
