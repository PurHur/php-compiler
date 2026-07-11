--TEST--
stream_get_meta_data() blocked flag on php://memory after stream_set_blocking(false) (#17928, ext/standard/streams.c)
--FILE--
<?php
$mem = fopen('php://memory', 'r+');
stream_set_blocking($mem, false);
echo ($memMeta = stream_get_meta_data($mem))['blocked'] === true ? 'mem' : 'mem-bad', "\n";
fclose($mem);

$temp = fopen('php://temp', 'r+');
stream_set_blocking($temp, false);
$tempMeta = stream_get_meta_data($temp);
echo array_key_exists('blocked', $tempMeta) ? 'temp-bad' : 'temp', "\n";
fclose($temp);
--EXPECT--
mem
temp
