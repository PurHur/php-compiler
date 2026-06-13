--TEST--
stdlib stream_socket_pair() — UNIX domain connected pair (#3437, ext/standard/streams.c)
--SKIPIF--
<?php if (!function_exists('stream_socket_pair')) die('skip stream_socket_pair'); ?>
--FILE--
<?php
declare(strict_types=1);
echo function_exists('stream_socket_pair') ? '1' : '0', "\n";
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if (false === $pair) {
    echo "pair_fail\n";
    exit(0);
}
[$a, $b] = $pair;
echo is_resource($a) && is_resource($b) ? "pair_ok\n" : "pair_bad\n";
fwrite($a, 'ping');
echo stream_get_contents($b), "\n";
fclose($a);
fclose($b);
?>
--EXPECT--
1
pair_ok
ping
