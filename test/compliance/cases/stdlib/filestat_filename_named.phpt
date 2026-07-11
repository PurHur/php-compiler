--TEST--
stdlib file_exists()/is_readable()/filesize() filename: named parameter (#12102, ext/standard/filestat.c)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_filestat_named_' . getmypid();
file_put_contents($path, 'x');
echo file_exists(filename: $path) ? "true\n" : "false\n";
echo is_readable(filename: $path) ? "true\n" : "false\n";
echo filesize(filename: $path) > 0 ? "true\n" : "false\n";
echo file_exists($path) ? "positional ok\n" : "positional fail\n";
@unlink($path);
--EXPECT--
true
true
true
positional ok
