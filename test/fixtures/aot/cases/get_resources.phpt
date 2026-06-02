--TEST--
AOT: get_resources() stream handle listing (issue #3646)
--FILE--
<?php
$before = count(get_resources());
$path = sys_get_temp_dir() . '/phpc_getres_' . (string) getmypid() . '.dat';
$h = fopen($path, 'w+');
$mid = count(get_resources());
fclose($h);
@unlink($path);
$after = count(get_resources());
echo $mid === $before + 1 ? '1' : '0';
echo $after === $before ? '1' : '0';
echo "\n";
--EXPECT--
11
