--TEST--
Language: foreach / in_array unchanged when `in` operator is rejected (#31158)
--FILE--
<?php
$a = [1, 2];
foreach ($a as $k => $v) {
    echo $k, ':', $v, "\n";
}
var_dump(in_array(1, $a, true));
var_dump(in_array(3, $a, true));
--EXPECT--
0:1
1:2
bool(true)
bool(false)
