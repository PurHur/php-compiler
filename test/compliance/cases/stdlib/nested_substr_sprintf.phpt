--TEST--
stdlib nested substr(sprintf()) — inner string preserved (#10673, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

echo substr(sprintf('%o', 33188), -4), "\n";
echo substr(dechex(255), -2), "\n";
echo substr(str_pad('1', 5, '0'), -3), "\n";
--EXPECT--
0644
ff
000
