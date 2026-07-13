--TEST--
JIT: str_getcsv() lone opening enclosure yields NUL field (#18592, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);

$row = str_getcsv('"');
echo 'count=', count($row), ' len=', strlen($row[0]), ' ord0=', ord($row[0]), "\n";
?>
--EXPECT--
count=1 len=1 ord0=0
