--TEST--
stdlib include_path stack — set, include, restore via set_include_path($old) (#3223, #11833)
--FILE--
<?php
foreach (['get_include_path', 'set_include_path', 'restore_include_path'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'no', "\n";
}
$dir = sys_get_temp_dir() . '/phpc_inc_' . getmypid();
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
get_include_path=yes
set_include_path=yes
restore_include_path=no
include='found'
restored
--CREDITS--
PurHur/php-compiler issue #3223
