--TEST--
stdlib asort() by value preserving keys (#2290)
--FILE--
<?php
$a = array('b' => 2, 'a' => 1, 'c' => 3);
asort($a);
foreach ($a as $k => $v) {
    echo $k, ':', $v, "\n";
}
$b = array(3, 1, 2);
asort($b);
echo implode(',', $b), "\n";
--EXPECT--
a:1
b:2
c:3
1,2,3
