--TEST--
stdlib str_getcsv() escape character inside quoted fields (#4173)
--FILE--
<?php
$row = str_getcsv('"a\"b",c');
echo strlen($row[0]), "\n";
echo $row[0], "\n";
$doubled = str_getcsv('"a""b",c');
echo strlen($doubled[0]), "\n";
echo $doubled[0], "\n";
--EXPECT--
4
a\"b
3
a"b
