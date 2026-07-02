--TEST--
JIT/AOT: opendir() on regular file — Warning Not a directory (#14861)
--FILE--
<?php
$path = sys_get_temp_dir().'/phpc_opendir_not_dir_jit_'.getmypid().'.txt';
file_put_contents($path, 'x');
$h = opendir($path);
unlink($path);
echo $h === false ? 'false' : 'not_false', "\n";
--EXPECTF--
PHP Warning:  opendir(%s): Failed to open directory: Not a directory in - on line %d
false
