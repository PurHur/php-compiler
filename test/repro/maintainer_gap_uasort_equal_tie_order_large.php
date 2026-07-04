<?php
// Maintainer repro for #13762 — uasort() ties with 17+ elements (quicksort path).
$rows = [];
for ($i = 0; $i < 20; ++$i) {
    $rows[chr(97 + $i)] = $i % 3;
}
uasort($rows, static fn ($x, $y) => $x <=> $y);
$order = implode(',', array_keys($rows));
$expect = 'a,d,g,j,m,p,s,b,e,h,k,n,q,t,c,f,i,l,o,r';
echo $expect === $order ? "ok\n" : "fail: key order $order expected $expect\n";
