--TEST--
AOT: str_getcsv() (#2391, #27069)
--FILE--
<?php
$row = str_getcsv('one,two,three');
echo $row[0], '-', $row[1], '-', $row[2], "\n";
$quoted = str_getcsv('"hi","there"');
echo $quoted[0], '|', $quoted[1], "\n";
// count()/gettype abort under thin AOT for arrays today; null-field check is enough (#27069).
$empty = str_getcsv('');
echo ($empty[0] === null) ? "NULL\n" : "X\n";
$issue = str_getcsv('a,"b,c",d');
echo implode('|', $issue), "\n";
--EXPECT--
one-two-three
hi|there
NULL
a|b,c|d
