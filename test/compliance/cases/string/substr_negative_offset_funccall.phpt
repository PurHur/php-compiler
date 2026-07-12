--TEST--
stdlib substr() negative offset on FuncCall haystack (#17572, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

echo substr(dechex(255), -2), "\n";
echo substr(str_pad('1', 5, '0'), -3), "\n";
echo substr(sprintf('%o', 33188), -4), "\n";
--EXPECT--
ff
000
0644
