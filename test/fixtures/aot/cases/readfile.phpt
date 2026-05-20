--TEST--
AOT readfile() streams bytes to stdout
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$n = readfile($path);
echo $n, "\n";
$bad = readfile('test/compliance/cases/stdlib/no-such-readfile-path');
echo $bad === false ? 'false' : 'bad', "\n";
--EXPECT--
hello readfile
15
false
