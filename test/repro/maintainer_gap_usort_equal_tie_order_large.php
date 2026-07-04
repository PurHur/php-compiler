<?php
// Maintainer repro for #13762 — usort() ties with 17+ elements (quicksort path).
$rows = [];
for ($i = 0; $i < 20; ++$i) {
    $rows[] = ['id' => chr(97 + $i), 'v' => $i % 3];
}
usort($rows, static fn ($x, $y) => $x['v'] <=> $y['v']);
$order = implode(',', array_column($rows, 'id'));
$expect = 'a,d,g,j,m,p,s,b,e,h,k,n,q,t,c,f,i,l,o,r';
echo $expect === $order ? "ok\n" : "fail: row order $order expected $expect\n";
