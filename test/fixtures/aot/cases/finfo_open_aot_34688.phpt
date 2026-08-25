--TEST--
finfo_open()/set_flags()/close() AOT MIME (#34688)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_finfo_open_aot_34688.txt';
file_put_contents($path, 'hello');
$f = finfo_open(FILEINFO_MIME_TYPE);
echo is_object($f) ? "obj\n" : "not\n";
echo finfo_file($f, $path), "\n";
echo finfo_set_flags($f, FILEINFO_MIME_TYPE) ? "set_ok\n" : "set_fail\n";
echo finfo_close($f) ? "close_ok\n" : "close_fail\n";
--EXPECT--
obj
text/plain
set_ok
close_ok
