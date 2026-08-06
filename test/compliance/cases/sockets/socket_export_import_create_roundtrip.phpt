--TEST--
socket_import_stream() after socket_export_stream(socket_create) returns Socket (#28139)
--SKIPIF--
<?php
if (!function_exists('socket_create')
    || !function_exists('socket_export_stream')
    || !function_exists('socket_import_stream')) {
    die('skip sockets');
}
?>
--FILE--
<?php
declare(strict_types=1);

$s = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
$stream = socket_export_stream($s);
echo is_resource($stream) ? '1' : '0', "\n";

$meta = stream_get_meta_data($stream);
$expected = extension_loaded('openssl') ? 'tcp_socket/ssl' : 'tcp_socket';
echo ($meta['stream_type'] ?? '') === $expected ? 'type_ok' : ('type_'.$meta['stream_type']), "\n";
echo isset($meta['wrapper_type']) ? 'has_wrapper' : 'no_wrapper', "\n";

$s2 = socket_import_stream($stream);
echo $s2 instanceof Socket ? 'Socket' : get_debug_type($s2), "\n";

socket_close($s);
?>
--EXPECT--
1
type_ok
no_wrapper
Socket
