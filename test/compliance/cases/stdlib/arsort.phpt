--TEST--
stdlib arsort() by value preserving keys (#2296)
--FILE--
<?php
$a = array('b' => 2, 'a' => 1, 'c' => 3);
arsort($a);
foreach ($a as $k => $v) {
    echo $k, ':', $v, "\n";
}
$b = array(3, 1, 2);
arsort($b);
echo implode(',', $b), "\n";
--EXPECT--
c:3
b:2
a:1
3,2,1
