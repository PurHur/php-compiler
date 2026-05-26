--TEST--
stdlib asort() integer-key associative (#2290)
--FILE--
<?php
$a = array(30 => 3, 10 => 1, 20 => 2);
asort($a);
foreach ($a as $k => $v) {
    echo $k, ':', $v, "\n";
}
--EXPECT--
10:1
20:2
30:3
