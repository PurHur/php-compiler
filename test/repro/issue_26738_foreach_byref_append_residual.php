<?php
/** Repro #26738 — foreach by-ref residual must survive append/COW (Zend FE_FETCH_RW). */
$a = [1, 2];
foreach ($a as &$v) {
    $v *= 10;
}
$a[] = 3;
foreach ($a as $v) {
    echo $v, ' ';
}
echo "\n";
var_export($a);
echo "\n";
