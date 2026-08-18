--TEST--
stdlib asort() SORT_NATURAL / SORT_NATURAL|SORT_FLAG_CASE (#32295, ext/standard/array.c)
--FILE--
<?php
$a = array('img2', 'img10', 'img1');
asort($a, SORT_NATURAL);
echo implode(',', $a), "\n";
$b = array('IMG12' => 'IMG12', 'img2' => 'img2', 'Img1' => 'Img1');
asort($b, SORT_NATURAL | SORT_FLAG_CASE);
echo implode(',', $b), "\n";
--EXPECT--
img1,img2,img10
Img1,img2,IMG12
