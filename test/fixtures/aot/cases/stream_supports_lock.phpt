--TEST--
AOT: stream_supports_lock() on temp file stream (issue #6039)
--FILE--
<?php
echo function_exists('stream_supports_lock') ? '1' : '0', "\n";
$fp = fopen('php://memory', 'r+');
echo stream_supports_lock($fp) ? '1' : '0', "\n";
fclose($fp);

$tf = fopen('/tmp/phpc_aot_stream_supports_lock.txt', 'w+');
echo stream_supports_lock($tf) ? '1' : '0', "\n";
fclose($tf);
@unlink('/tmp/phpc_aot_stream_supports_lock.txt');
--EXPECT--
1
0
1
