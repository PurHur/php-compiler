--TEST--
JIT: str_getcsv() lone quote — unterminated empty enclosure yields NUL byte (#18592, ext/standard/file.c)
--FILE--
<?php
$row = str_getcsv('"');
echo count($row), '|', strlen($row[0]), '|', ord($row[0]), "\n";
--EXPECT--
1|1|0
