--TEST--
stdlib str_getcsv() lone quote — unterminated empty enclosure yields NUL byte (#18592, ext/standard/file.c)
--FILE--
<?php
$row = str_getcsv('"');
echo count($row), '|', strlen($row[0]), '|', ord($row[0]), "\n";
$closed = str_getcsv('""');
echo count($closed), '|', strlen($closed[0]), "\n";
$trail = str_getcsv('"field","');
echo strlen($trail[0]), '|', strlen($trail[1]), '|', ord($trail[1][0]), "\n";
--EXPECT--
1|1|0
1|0
5|1|0
