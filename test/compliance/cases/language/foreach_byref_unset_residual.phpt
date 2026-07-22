--TEST--
foreach by-ref unset current then by-value residual writes last element (Zend/zend_execute.c, #21985)
--FILE--
<?php
$a = [1, 2, 3];
foreach ($a as &$v) {
    if ($v === 2) {
        unset($a[1]);
    }
}
echo json_encode($a), "\n";
foreach ($a as $v) {
}
echo json_encode($a), "\n";
$b = [1, 2, 3];
foreach ($b as &$v) {
}
foreach ($b as $v) {
}
echo json_encode($b), "\n";
$c = [1, 2, 3];
foreach ($c as &$v) {
    if ($v === 2) {
        unset($c[1]);
    }
}
unset($v);
foreach ($c as $v) {
}
echo json_encode($c), "\n";
--EXPECT--
{"0":1,"2":3}
{"0":1,"2":1}
[1,2,2]
{"0":1,"2":3}
