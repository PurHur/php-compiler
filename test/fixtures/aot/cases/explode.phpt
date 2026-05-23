--TEST--
AOT: explode() splits string into indexed list
--FILE--
<?php
$parts = explode(',', 'a,b,c');
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], "\n";
$n = count(explode(',', ''));
echo $n, "\n";
--EXPECT--
3
a|b|c
1
