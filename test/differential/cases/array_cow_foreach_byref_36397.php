<?php
// @differential-repeat: 10 foreach-by-ref FE_RESET_RW must SEPARATE shared HT (#36397 / php-src ZEND_FE_RESET_RW)
$b = [1, 2];
$a = $b;
foreach ($a as &$v) {
    $v *= 10;
}
unset($v);
echo implode(',', $b), '|', implode(',', $a), "\n";

// String-key: constant assign through borrowed entry (#36366 emitForeachByRefAssign).
$d = ['x' => 1, 'y' => 2];
$c = $d;
foreach ($c as &$w) {
    $w = 9;
}
unset($w);
echo $d['x'], ',', $d['y'], '|', $c['x'], ',', $c['y'], "\n";

// String-key RMW: do not hydrate walk-index as FETCH_DIM_W packed slot (#36397).
// php-src: ZEND_FE_FETCH_RW borrows zval*; ASSIGN_OP / $w = $w + 1 mutate in place.
$d2 = ['x' => 1, 'y' => 2];
$c2 = $d2;
foreach ($c2 as &$w2) {
    $w2 = $w2 + 1;
}
unset($w2);
echo implode(',', $c2), '|', implode(',', $d2), "\n";

$d3 = ['x' => 1, 'y' => 2];
$c3 = $d3;
foreach ($c3 as &$w3) {
    $w3 += 1;
}
unset($w3);
echo implode(',', $c3), '|', implode(',', $d3), "\n";
