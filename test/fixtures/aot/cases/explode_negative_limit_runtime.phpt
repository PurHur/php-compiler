--TEST--
AOT: explode() negative runtime $limit (ext/standard/string.c, #4077)
--FILE--
<?php
declare(strict_types=1);
$limit = -2;
$parts = explode('-', 'a-b-c-d', $limit);
echo count($parts), ':', $parts[0], '|', $parts[1], "\n";
$limit = -10;
$empty = explode('-', 'a-b-c-d', $limit);
echo count($empty), "\n";
--EXPECT--
2:a|b
0
