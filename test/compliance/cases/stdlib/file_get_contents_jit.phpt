--TEST--
JIT: file_get_contents() reads a file into a string
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
echo file_get_contents($path), "\n";
$bad = file_get_contents('test/compliance/cases/stdlib/no-such-file-get-contents-path');
echo $bad === false ? 'false' : 'bad', "\n";
--EXPECT--
hello readfile
false
