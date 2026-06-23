--TEST--
get_resource_type() on opendir() handle — JIT (#10703)
--FILE--
<?php
$dh = opendir(sys_get_temp_dir());
echo get_resource_type($dh), "\n";
closedir($dh);
--EXPECT--
stream
