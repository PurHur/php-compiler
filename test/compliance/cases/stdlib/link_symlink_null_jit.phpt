--TEST--
stdlib link()/symlink() null path JIT — coerce to empty + false (#18710, ext/standard/filestat.c)
--JIT--
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$path = sys_get_temp_dir() . '/phpc_link_symlink_null_jit_' . getmypid();
var_export(@link(null, $path));
echo "\n";
var_export(@symlink(null, $path));
echo "\n";
--EXPECT--
false
false
