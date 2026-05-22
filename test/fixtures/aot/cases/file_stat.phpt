--TEST--
AOT: file_exists(), is_file(), and is_dir() via stat
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/readfile_fixture';
$path = $base . '/data.txt';
echo file_exists($path) ? 'exists' : 'missing', "\n";
echo is_file($path) ? 'file' : 'notfile', "\n";
echo is_dir($base) ? 'dir' : 'notdir', "\n";
echo file_exists('/no/such/phpc-stat-path') ? 'bad' : 'gone', "\n";
echo is_file($base) ? 'bad' : 'notfile', "\n";
echo is_dir($path) ? 'bad' : 'notdir', "\n";
--EXPECT--
exists
file
dir
gone
notfile
notdir
