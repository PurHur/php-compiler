--TEST--
stream_isatty() on php://memory and plainfile (issue #6035, php-src streamsfuncs.c)
--FILE--
<?php
echo function_exists('stream_isatty') ? '1' : '0', "\n";
$memory = fopen('php://memory', 'r+');
echo stream_isatty($memory) ? '1' : '0', "\n";
fclose($memory);
$path = sys_get_temp_dir() . '/phpc_stream_isatty_' . (string) getmypid() . '.txt';
$fp = fopen($path, 'w');
echo stream_isatty($fp) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
--EXPECT--
1
0
0
