--TEST--
Generator by-ref yield of array dim writebacks under JIT (Zend/zend_generators.c, #25877)
--FILE--
<?php
function &g_elem(array &$arr) {
    yield $arr[0];
}
$a = [1];
foreach (g_elem($a) as &$v) {
    $v = 9;
}
echo "elem=", $a[0], "\n";

function &g_each(array &$arr) {
    foreach ($arr as $k => &$v) {
        yield $k => $v;
    }
}
$b = [1, 2, 3];
foreach (g_each($b) as $k => &$v) {
    $v *= 10;
}
unset($v);
echo "each=", json_encode($b), "\n";
--EXPECT--
elem=9
each=[10,20,30]
