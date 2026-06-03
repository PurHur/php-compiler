--TEST--
AOT: stream_supports() on temp file stream (issue #5062)
--FILE--
<?php
echo function_exists('stream_supports') ? '1' : '0', "\n";
$path = sys_get_temp_dir() . '/phpc_aot_stream_supports.txt';
$fp = fopen($path, 'w+');
echo stream_supports($fp, STREAM_LOCK) ? '1' : '0', "\n";
echo stream_supports($fp, STREAM_META_TOUCH) ? '1' : '0', "\n";
echo stream_supports($fp, STREAM_FILTER) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
1
1
1
