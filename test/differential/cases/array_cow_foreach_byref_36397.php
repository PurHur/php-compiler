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
// RMW (`$w += 1` / `$w = $w + 1`) still red on string keys — follow-up, not this slice.
$d = ['x' => 1, 'y' => 2];
$c = $d;
foreach ($c as &$w) {
    $w = 9;
}
unset($w);
echo $d['x'], ',', $d['y'], '|', $c['x'], ',', $c['y'], "\n";
