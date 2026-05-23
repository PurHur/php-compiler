--TEST--
AOT: explode() splits string into indexed list
--FILE--
<?php
$parts = explode(',', 'a,b,c');
echo count($parts), "\n";
echo implode('|', $parts), "\n";
echo count(explode(',', '')), "\n";
--EXPECT--
3
a|b|c
1
