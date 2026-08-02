<?php
// Repro #26775 — AOT RecursiveIteratorIterator LEAVES_ONLY flatten (php-src ext/spl/spl_iterators.c).
$arr = ['a' => [1, 2], 'b' => [3]];
$it = new RecursiveArrayIterator($arr);
$flat = new RecursiveIteratorIterator($it);
$out = [];
foreach ($flat as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
