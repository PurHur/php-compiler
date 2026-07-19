--TEST--
socket_get_status()/socket_set_blocking()/socket_set_timeout() aliases of stream_* (issue #20903)
--FILE--
<?php
declare(strict_types=1);
echo function_exists('socket_get_status') ? '1' : '0', "\n";
echo function_exists('socket_set_blocking') ? '1' : '0', "\n";
echo function_exists('socket_set_timeout') ? '1' : '0', "\n";

$mem = fopen('php://memory', 'r+');
$meta = socket_get_status($mem);
echo is_array($meta) && ($meta['wrapper_type'] ?? '') === 'PHP' ? '1' : '0', "\n";
fclose($mem);

$f = tmpfile();
echo socket_set_blocking($f, true) ? '1' : '0', "\n";
echo socket_set_blocking($f, false) ? '1' : '0', "\n";
$path = sys_get_temp_dir() . '/phpc_socket_stream_aliases.txt';
$fp = fopen($path, 'w');
echo socket_set_timeout($fp, 1, 0) ? '1' : '0', "\n";
fclose($fp);
fclose($f);
@unlink($path);
--EXPECT--
1
1
1
1
1
1
1
