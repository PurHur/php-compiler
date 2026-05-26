--TEST--
stdlib str_getcsv()
--FILE--
<?php
$row = str_getcsv('a,b,c');
echo $row[0], '-', $row[1], '-', $row[2], "\n";
$quoted = str_getcsv('"hello","world"');
echo $quoted[0], '|', $quoted[1], "\n";
$empty = str_getcsv('');
echo sizeof($empty), '|', $empty[0], "\n";
--EXPECT--
a-b-c
hello|world
1|
