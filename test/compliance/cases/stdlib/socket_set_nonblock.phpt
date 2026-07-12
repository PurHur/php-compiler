--TEST--
socket_set_nonblock()/socket_set_block() on imported Socket stream (#6289, ext/sockets/sockets.c)
--SKIPIF--
<?php
if (!function_exists('socket_set_nonblock') || !function_exists('stream_socket_pair')) {
    die('skip sockets FFI or stream_socket_pair unavailable');
}
?>
--FILE--
<?php
declare(strict_types=1);

$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$socket = socket_import_stream($pair[0]);
echo var_export(socket_set_nonblock($socket), true), "\n";
echo var_export(socket_set_block($socket), true), "\n";
--EXPECT--
true
true
