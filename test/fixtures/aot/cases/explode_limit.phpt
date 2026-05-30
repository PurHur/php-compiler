--TEST--
AOT: explode() limit parameter caps segment count
--FILE--
<?php
$parts = explode('-', 'a-b-c-d', 2);
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], "\n";
$one = explode('-', 'a-b-c-d', 1);
echo count($one), "\n";
echo $one[0], "\n";
--EXPECT--
2
a|b-c-d
1
a-b-c-d
