--TEST--
stdlib explode() trailing empty segment
--FILE--
<?php
$parts = explode(',', 'a,b,');
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], "\n";
--EXPECT--
3
a|b|
