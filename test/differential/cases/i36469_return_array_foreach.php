<?php
// #36469: foreach over an untyped function-returned array must match Zend
// (mixed call results must not be tagged classUserType=object for ObjectProperty foreach).
function packed() { return [10, 20, 30]; }
function assoc() { return ['a' => 1, 'b' => 2]; }
$p = packed();
foreach ($p as $v) {
    echo 'p:', $v, "\n";
}
$a = assoc();
foreach ($a as $k => $v) {
    echo 'a:', $k, '=', $v, "\n";
}
