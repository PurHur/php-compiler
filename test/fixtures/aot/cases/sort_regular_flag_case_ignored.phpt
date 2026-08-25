--TEST--
AOT: sort()/rsort() ignore SORT_FLAG_CASE with SORT_REGULAR (#34702, ext/standard/array.c)
--FILE--
<?php
$a = array('B', 'a', 'C');
sort($a, SORT_REGULAR | SORT_FLAG_CASE);
echo $a[0], ',', $a[1], ',', $a[2], "\n";
$b = array('B', 'a', 'C');
rsort($b, SORT_REGULAR | SORT_FLAG_CASE);
echo $b[0], ',', $b[1], ',', $b[2], "\n";
--EXPECT--
B,C,a
a,C,B
