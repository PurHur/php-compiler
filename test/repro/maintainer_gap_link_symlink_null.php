<?php
// Repro #18710: link()/symlink() null $target must coerce to '' + false, not TypeError.
error_reporting(E_ALL & ~E_DEPRECATED);
$path = sys_get_temp_dir() . '/phpc_link_symlink_null_' . getmypid();
var_export(@link(null, $path));
echo "\n";
var_export(@symlink(null, $path));
echo "\n";
