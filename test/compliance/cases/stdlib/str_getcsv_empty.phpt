--TEST--
stdlib str_getcsv() empty input yields NULL field (#4922)
--FILE--
<?php
$empty = str_getcsv('');
echo count($empty), '|', gettype($empty[0]), "\n";
$comma = str_getcsv(',');
echo count($comma), '|', gettype($comma[0]), '|', gettype($comma[1]), "\n";
--EXPECT--
1|NULL
2|string|string
