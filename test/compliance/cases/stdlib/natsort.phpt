--TEST--
stdlib natsort() natural order by value (#2358)
--FILE--
<?php
$a = array('img12', 'img10', 'img2', 'img1');
natsort($a);
echo implode(',', $a), "\n";
$b = array('b' => 'item10', 'a' => 'item2', 'c' => 'item1');
natsort($b);
foreach ($b as $k => $v) {
    echo $k, ':', $v, "\n";
}
$c = array(30, 10, 20);
natsort($c);
echo implode(',', $c), "\n";
--EXPECT--
img1,img2,img10,img12
c:item1
a:item2
b:item10
10,20,30
