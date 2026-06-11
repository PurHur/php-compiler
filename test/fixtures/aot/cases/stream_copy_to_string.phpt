--TEST--
AOT: stream_copy_to_string() (#6547)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc-scts-aot-' . getmypid() . '.txt';
file_put_contents($path, 'aot string');
$src = fopen($path, 'rb');
echo stream_copy_to_string($src), "\n";
fclose($src);
@unlink($path);
--EXPECT--
aot string
--CREDITS--
PurHur/php-compiler #6547
