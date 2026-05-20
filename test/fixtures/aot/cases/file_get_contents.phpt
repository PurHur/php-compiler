--TEST--
AOT: file_get_contents() reads a file into a string
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
echo file_get_contents($path);
--EXPECT--
hello readfile
