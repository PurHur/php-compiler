--TEST--
AOT: sort()/rsort() SORT_STRING|SORT_FLAG_CASE (#34702)
--FILE--
<?php
$a = array('B', 'a', 'C');
sort($a, SORT_STRING | SORT_FLAG_CASE);
echo $a[0], ',', $a[1], ',', $a[2], "\n";
$b = array('B', 'a', 'C');
rsort($b, SORT_STRING | SORT_FLAG_CASE);
echo $b[0], ',', $b[1], ',', $b[2], "\n";
--EXPECT--
a,B,C
C,B,a
