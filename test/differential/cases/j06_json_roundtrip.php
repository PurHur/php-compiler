<?php
// #24137: json_decode() of a RUNTIME-produced string — encode→decode roundtrip.
$d = ['a' => 1, 'b' => [2, 3]];
$j = json_encode($d);
$r = json_decode($j, true);
echo $j, ' ', $r['a'], ' ', $r['b'][1], "\n";
