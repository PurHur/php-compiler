--TEST--
AOT: explode() named separator:/string:/limit: arguments (#10034, ext/standard/string.c)
--FILE--
<?php
$parts = explode(separator: ',', string: 'a,b,c');
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], "\n";
$limited = explode(separator: '-', string: 'a-b-c', limit: 2);
echo count($limited), "\n";
echo $limited[0], '|', $limited[1], "\n";
--EXPECT--
3
a|b|c
2
a|b-c
