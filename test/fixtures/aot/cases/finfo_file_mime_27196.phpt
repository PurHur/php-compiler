--TEST--
finfo_file() / finfo::file() AOT MIME (#27196)
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc_finfo_aot_27196.txt';
file_put_contents($path, 'hello');
$f = new finfo(FILEINFO_MIME_TYPE);
echo $f->file($path), "\n";
echo finfo_file($f, $path), "\n";
--EXPECT--
text/plain
text/plain
