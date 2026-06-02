--TEST--
stdlib get_resources() — stream handle listing (VM, #3646)
--FILE--
<?php
echo function_exists('get_resources') ? '1' : '0';
$before = count(get_resources());
$path = sys_get_temp_dir() . '/phpc_getres_' . (string) getmypid() . '.dat';
$h = fopen($path, 'w+');
$mid = count(get_resources());
$streamOnly = count(get_resources('stream'));
fclose($h);
@unlink($path);
$after = count(get_resources());
echo $mid === $before + 1 ? '1' : '0';
echo $streamOnly === $mid ? '1' : '0';
echo $after === $before ? '1' : '0';
echo "\n";
--EXPECT--
1111
