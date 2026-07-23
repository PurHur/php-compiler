--TEST--
socket_export_stream() after socket_create() wraps live fd (#22542, ext/sockets/sockets.c)
--SKIPIF--
<?php
if (!function_exists('socket_create') || !function_exists('socket_export_stream')) {
    die('skip sockets');
}
?>
--FILE--
<?php
declare(strict_types=1);

$s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
$st = socket_export_stream($s);
echo is_resource($st) ? '1' : '0', "\n";
echo get_resource_type($st), "\n";

$meta = stream_get_meta_data($st);
echo $meta['stream_type'] ?? 'missing', "\n";
echo ($meta['mode'] ?? '') === 'r+' ? 'r+' : 'mode', "\n";

$st2 = socket_export_stream($s);
echo $st === $st2 ? 'same' : 'diff', "\n";

// Import→export round-trip still works
if (function_exists('stream_socket_pair')) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (false !== $pair) {
        [$a, $b] = $pair;
        $sock = socket_import_stream($a);
        $exp = socket_export_stream($sock);
        echo is_resource($exp) ? 'import_ok' : 'import_fail', "\n";
        fclose($a);
        fclose($b);
    } else {
        echo "import_skip\n";
    }
} else {
    echo "import_skip\n";
}

socket_close($s);
--EXPECT--
1
stream
udp_socket
r+
same
import_ok
