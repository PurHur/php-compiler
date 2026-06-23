--TEST--
stdlib file_get_contents() filename: named parameter (#10045, ext/standard/file.c)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
var_export(file_get_contents(filename: $path));
echo "\n";
var_export(file_get_contents($path));
echo "\n";
--EXPECT--
'hello readfile
'
'hello readfile
'
