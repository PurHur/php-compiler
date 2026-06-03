--TEST--
stdlib stream_get_contents / get_resource_type bootstrap AOT parity (#3142)
--FILE--
<?php
declare(strict_types=1);
$path = sys_get_temp_dir() . '/phpc_sgc_aot_' . (string) getmypid() . '.txt';
file_put_contents($path, 'offsettail');
$h = fopen($path, 'r');
$type = get_resource_type($h);
$chunk = stream_get_contents($h, 6, 6);
fclose($h);
@unlink($path);
echo $type === 'stream' && $chunk === 'tail' ? '1' : '0';
echo "\n";
--EXPECT--
1
