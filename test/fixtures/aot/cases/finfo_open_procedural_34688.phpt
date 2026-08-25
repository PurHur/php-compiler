--TEST--
finfo_open() / finfo_set_flags() / finfo_close() AOT procedural (#34688)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_finfo_aot_34688.txt';
file_put_contents($path, 'hello');
$f = finfo_open(FILEINFO_MIME_TYPE);
var_dump(is_object($f));
var_dump(finfo_set_flags($f, FILEINFO_MIME_TYPE));
echo finfo_file($f, $path), "\n";
var_dump(finfo_close($f));
--EXPECT--
bool(true)
bool(true)
text/plain
bool(true)
