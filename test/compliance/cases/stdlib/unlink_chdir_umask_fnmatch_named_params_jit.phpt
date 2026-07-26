--TEST--
unlink/chdir/umask/fnmatch named args (JIT, issue #23461)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_unlink_named_jit_' . getmypid();
file_put_contents($path, 'x');
var_export(unlink(filename: $path));
echo "\n";
$cwd = getcwd();
var_export(chdir(directory: sys_get_temp_dir()));
echo "\n";
chdir($cwd);
$prev = umask(mask: 0022);
var_export(is_int($prev));
echo "\n";
umask($prev);
var_export(fnmatch(pattern: 'a*', filename: 'abc'));
echo "\n";
--EXPECT--
true
true
true
true
