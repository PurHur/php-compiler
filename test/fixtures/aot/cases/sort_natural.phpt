--TEST--
AOT: sort() with SORT_NATURAL and EXTR_SKIP constants (#3372)
--FILE--
<?php
$a = array();
$a[] = 'a10';
$a[] = 'a2';
sort($a, SORT_NATURAL);
echo $a[0], ',', $a[1], "\n";
echo (SORT_NATURAL === 6 && EXTR_SKIP === 1 && SORT_ASC === 4) ? "const_ok\n" : "const_bad\n";
--EXPECT--
a2,a10
const_ok
