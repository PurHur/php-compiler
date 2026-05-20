--TEST--
stdlib file_put_contents and file_get_contents
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/file_io_fixture';
$path = $base . '/cache/hit.txt';
$n = file_put_contents($path, 'ok');
echo $n, "\n";
echo file_get_contents($path), "\n";
echo file_exists($path) ? 'exists' : 'missing', "\n";
echo is_file($path) ? 'file' : 'notfile', "\n";
echo is_dir($base . '/cache') ? 'dir' : 'notdir', "\n";
$fp = fopen($path, 'r');
$chunk = fread($fp, 2);
fclose($fp);
echo $chunk, "\n";
--EXPECT--
2
ok
exists
file
dir
ok
