--TEST--
AOT: get_resources() stream handle listing (issue #3646)
--FILE--
<?php
$beforeStreams = count(get_resources('stream'));
$path = sys_get_temp_dir() . '/phpc_getres_' . (string) getmypid() . '.dat';
$h = fopen($path, 'w+');
$streamMid = count(get_resources('stream'));
fclose($h);
@unlink($path);
$afterStreams = count(get_resources('stream'));
echo $streamMid === $beforeStreams + 1 ? '1' : '0';
echo $afterStreams === $beforeStreams ? '1' : '0';
echo "\n";
--EXPECT--
11
