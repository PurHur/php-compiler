--TEST--
foreach by-ref then append then by-value residual writes last pre-append element (Zend/zend_execute.c, #26738)
--FILE--
<?php
$a = [1, 2];
foreach ($a as &$v) {
    $v *= 10;
}
$a[] = 3;
$echo = [];
foreach ($a as $v) {
    $echo[] = $v;
}
echo implode(' ', $echo), "\n";
echo json_encode($a), "\n";
// Control: plain residual without append (#21985)
$b = [1, 2, 3];
foreach ($b as &$v) {
}
foreach ($b as $v) {
}
echo json_encode($b), "\n";
unset($v);
$c = [1, 2];
foreach ($c as &$v) {
    $v *= 10;
}
$c[] = 3;
unset($v);
foreach ($c as $v) {
}
echo json_encode($c), "\n";
--EXPECT--
10 10 3
[10,3,3]
[1,2,2]
[10,20,3]
