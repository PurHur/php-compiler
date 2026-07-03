--TEST--
socket_import_stream() — registered on VM without host delegation (issue #6203)
--SKIPIF--
<?php if (!function_exists('socket_import_stream')) die('skip socket_import_stream'); ?>
--FILE--
<?php
declare(strict_types=1);
echo (int) function_exists('socket_import_stream'), "\n";
if (!function_exists('stream_socket_pair')) {
    echo "pair_skip\n";
    exit(0);
}
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if (false === $pair) {
    echo "pair_fail\n";
    exit(0);
}
[$a, $b] = $pair;
$sock = socket_import_stream($a);
var_export($sock instanceof Socket);
echo "\n";
fclose($a);
fclose($b);
?>
--EXPECT--
1
true
