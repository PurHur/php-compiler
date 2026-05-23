--TEST--
AOT: explode() and implode() for string lists
--FILE--
<?php
$parts = explode(',', 'a,b,c');
echo count($parts), "\n";
echo implode('|', $parts), "\n";
--EXPECT--
3
a|b|c
