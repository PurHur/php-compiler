--TEST--
AOT: stream_is_local() on php://memory and plainfile (issue #10487, php-src streamsfuncs.c)
--FILE--
<?php
$memory = fopen('php://memory', 'r+');
echo stream_is_local($memory) ? '1' : '0', "\n";
fclose($memory);
$path = sys_get_temp_dir() . '/phpc_aot_stream_is_local_' . (string) getmypid() . '.txt';
$fp = fopen($path, 'w');
echo stream_is_local($fp) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
1
