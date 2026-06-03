--TEST--
stdlib stream_get_line() AOT via __compiler_stream_get_line (issue #3738)
--FILE--
<?php
declare(strict_types=1);
$path = sys_get_temp_dir() . '/phpc_sgl_aot_' . (string) getmypid() . '.txt';
file_put_contents($path, "hello\nworld");
$fp = fopen($path, 'r');
$line = stream_get_line($fp, 1024, "\n");
fclose($fp);
@unlink($path);
echo $line === 'hello' ? '1' : '0';
echo "\n";
--EXPECT--
1
