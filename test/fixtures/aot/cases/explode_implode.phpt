--TEST--
AOT: explode() and implode() for string lists
--FILE--
<?php
declare(strict_types=1);
$parts = explode(',', 'a,b,c');
echo count($parts), "\n";
echo implode('|', $parts), "\n";
echo count(explode(',', '')), "\n";
echo implode('|', explode(',', 'solo')), "\n";
--EXPECT--
3
a|b|c
1
solo
