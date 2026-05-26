--TEST--
JIT: str_getcsv()
--FILE--
<?php
$row = str_getcsv('x,y,z');
echo $row[0], '-', $row[1], '-', $row[2], "\n";
$quoted = str_getcsv('"a","b"');
echo $quoted[0], '|', $quoted[1], "\n";
--EXPECT--
x-y-z
a|b
