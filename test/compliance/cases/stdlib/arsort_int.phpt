--TEST--
stdlib arsort() integer-key associative (#2296)
--FILE--
<?php
$a = array(30 => 3, 10 => 1, 20 => 2);
arsort($a);
foreach ($a as $k => $v) {
    echo $k, ':', $v, "\n";
}
--EXPECT--
30:3
20:2
10:1
