--TEST--
get_resource_type() on opendir() handle returns stream (#10703, ext/standard/basic_functions.c)
--FILE--
<?php
$dh = opendir(sys_get_temp_dir());
echo is_resource($dh) ? 'yes' : 'no', "\n";
echo get_resource_type($dh), "\n";
$f = fopen('php://memory', 'r+');
echo get_resource_type($f), "\n";
fclose($f);
closedir($dh);
--EXPECT--
yes
stream
stream
