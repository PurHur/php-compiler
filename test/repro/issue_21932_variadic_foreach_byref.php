<?php
/** Repro #21932 — foreach by-ref over variadic &...$args (Zend FE_FETCH_RW). */
function f_varref(&...$a) {
    foreach ($a as &$x) {
        $x++;
    }
}
$x = 1;
$y = 2;
f_varref($x, $y);
echo "$x,$y\n";
