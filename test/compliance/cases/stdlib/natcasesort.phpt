--TEST--
stdlib natcasesort() natural case-insensitive order by value (#2372)
--FILE--
<?php
$a = array('Img12', 'img10', 'IMG2', 'img1');
natcasesort($a);
echo implode(',', $a), "\n";
$b = array('b' => 'V10', 'a' => 'v2', 'c' => 'V1');
natcasesort($b);
foreach ($b as $k => $v) {
    echo $k, ':', $v, "\n";
}
--EXPECT--
img1,IMG2,img10,Img12
c:V1
a:v2
b:V10
