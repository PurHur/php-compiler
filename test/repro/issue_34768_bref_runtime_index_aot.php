<?php
// #34768 — by-ref return of $a[count($a)-1] must alias the live HT entry.
function &f(array &$a) {
    $a[] = 1;
    return $a[count($a) - 1];
}
$a = [];
$r = &f($a);
$r = 8;
var_dump($a);
