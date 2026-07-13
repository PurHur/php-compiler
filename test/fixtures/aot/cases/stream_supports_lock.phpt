--TEST--
AOT: stream_supports_lock() on temp file stream (issue #6039)
--FILE--
<?php
echo function_exists('stream_supports_lock') ? '1' : '0', "\n";
$fp = fopen('php://memory', 'r+');
echo (int) stream_supports_lock($fp), "\n";
fclose($fp);
--EXPECT--
1
0
