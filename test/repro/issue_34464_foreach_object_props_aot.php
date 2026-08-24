<?php
// Repro #34464 — multi-prop user-object foreach must match Zend under AOT.
class A {
    public $a = 1;
    public $b = 2;
}
$out = [];
foreach (new A as $k => $v) {
    $out[] = "$k=$v";
}
echo implode(',', $out), "\n";
$o = new A;
$out = [];
foreach ($o as $k => $v) {
    $out[] = "$k=$v";
}
echo implode(',', $out), "\n";
