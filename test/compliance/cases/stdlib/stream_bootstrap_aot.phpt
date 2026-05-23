--TEST--
stdlib fopen/fread/fclose bootstrap AOT parity (#1117)
--FILE--
<?php
declare(strict_types=1);
$path = sys_get_temp_dir() . '/phpc_stream_' . (string) getmypid() . '.txt';
file_put_contents($path, 'data');
$h = fopen($path, 'r');
$chunk = fread($h, 4);
fclose($h);
@unlink($path);
echo is_string($chunk) && $chunk === 'data' ? '1' : '0';
echo "\n";
--EXPECT--
1
