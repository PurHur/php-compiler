--TEST--
AOT: stream_set_timeout() / stream_set_chunk_size() (issue #3754)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_aot_stream_set.txt';
$fp = fopen($path, 'w');
echo stream_set_chunk_size($fp, 4096), "\n";
echo stream_set_timeout($fp, 1) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
8192
1
