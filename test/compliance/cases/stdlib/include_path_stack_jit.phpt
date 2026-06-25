--TEST--
JIT/AOT include_path stack — set, include, restore (issue #3223)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_inc_jit_' . getmypid();
mkdir($dir);
file_put_contents($dir . '/only_here.php', '<?php return "found";');
$old = set_include_path($dir . PATH_SEPARATOR . get_include_path());
$r = include 'only_here.php';
set_include_path($old);
unlink($dir . '/only_here.php');
rmdir($dir);
echo 'include=', var_export($r, true), "\n";
echo get_include_path() === $old ? "restored\n" : "notrestored\n";
--EXPECT--
include='found'
restored
--CREDITS--
PurHur/php-compiler issue #3223
