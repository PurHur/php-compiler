--TEST--
AOT: shuffle() preserves multiset (#2310)
--FILE--
<?php
$a = array();
$a[] = 5;
$a[] = 3;
$a[] = 4;
shuffle($a);
sort($a);
echo $a[0], ':', $a[2], "\n";
--EXPECT--
3:5
