--TEST--
stream_set_timeout() / stream_set_chunk_size() on memory stream (issue #3754)
--FILE--
<?php
echo function_exists('stream_set_timeout') ? '1' : '0', "\n";
echo function_exists('stream_set_chunk_size') ? '1' : '0', "\n";
$fp = fopen('php://memory', 'r+');
$prev = stream_set_chunk_size($fp, 4096);
echo false !== $prev ? 'chunk' : 'no', "\n";
echo stream_set_timeout($fp, 1, 0) ? '1' : '0', "\n";
fclose($fp);
--EXPECT--
1
1
chunk
1
