--TEST--
stdlib get_resources() — stream handle listing (VM, #3646)
--FILE--
<?php
echo function_exists('get_resources') ? '1' : '0';
$beforeStreams = count(get_resources('stream'));
$path = sys_get_temp_dir() . '/phpc_getres_' . (string) getmypid() . '.dat';
$h = fopen($path, 'w+');
$midStreams = count(get_resources('stream'));
$midTotal = count(get_resources());
fclose($h);
@unlink($path);
$afterStreams = count(get_resources('stream'));
echo $midStreams === $beforeStreams + 1 ? '1' : '0';
echo $midTotal >= $midStreams ? '1' : '0';
echo $afterStreams === $beforeStreams ? '1' : '0';
echo "\n";
--EXPECT--
1111
