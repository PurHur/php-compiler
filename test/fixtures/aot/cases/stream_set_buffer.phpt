--TEST--
AOT: stream_set_write_buffer() / stream_set_read_buffer() (issue #3755 / #30788)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_aot_stream_set_buffer.txt';
$fp = fopen($path, 'w');
echo stream_set_write_buffer($fp, 0), "\n";
echo stream_set_read_buffer($fp, 0), "\n";
echo set_file_buffer($fp, 0), "\n";
fclose($fp);
@unlink($path);
--EXPECT--
-1
0
-1
