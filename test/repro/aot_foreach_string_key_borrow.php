<?php
// #34635 — AOT foreach string keys must not borrow HT key storage
$a = ['a' => 1, 'b' => 2, 'c' => 3];
foreach ($a as $k => $v) {
}
echo implode(',', array_keys($a)), "\n";
echo $a['b'], "\n";
