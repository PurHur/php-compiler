--TEST--
stdlib explode() limit parameter (php-src ext/standard/string.c)
--FILE--
<?php
$parts = explode('-', 'a-b-c-d', 2);
echo count($parts), "\n";
echo implode('|', $parts), "\n";
$one = explode('-', 'a-b-c-d', 1);
echo count($one), "\n";
echo $one[0], "\n";
$three = explode('-', 'a-b-c-d', 3);
echo count($three), "\n";
echo implode('|', $three), "\n";
$neg = explode('-', 'a-b-c-d', -1);
echo count($neg), "\n";
echo implode('|', $neg), "\n";
echo implode('|', explode(',', 'solo', 2)), "\n";
--EXPECT--
2
a|b-c-d
1
a-b-c-d
3
a|b|c-d
3
a|b|c
solo
