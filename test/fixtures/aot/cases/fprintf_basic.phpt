--TEST--
AOT: fprintf() variadic stream write (#3301)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_fprintf_aot_' . getmypid() . '.txt';
$fp = fopen($path, 'w+');
$n = fprintf($fp, '%s-%d', 'a', 3);
fclose($fp);
echo file_get_contents($path), " bytes=$n\n";
@unlink($path);
--EXPECT--
a-3 bytes=3
