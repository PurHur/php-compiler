--TEST--
AOT: str_getcsv() (#2391)
--FILE--
<?php
$row = str_getcsv('one,two,three');
echo $row[0], '-', $row[1], '-', $row[2], "\n";
$quoted = str_getcsv('"hi","there"');
echo $quoted[0], '|', $quoted[1], "\n";
--EXPECT--
one-two-three
hi|there
