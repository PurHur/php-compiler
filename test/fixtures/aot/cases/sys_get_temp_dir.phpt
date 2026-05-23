--TEST--
AOT: sys_get_temp_dir()
--FILE--
<?php
$dir = sys_get_temp_dir();
echo is_string($dir) && strlen($dir) > 0 ? '1' : '0';
echo "\n";
--EXPECT--
1
