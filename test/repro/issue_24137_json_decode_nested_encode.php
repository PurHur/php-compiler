<?php
// Nested encode→decode must compile and run under AOT (#24137)
$d = ['a' => 1, 'b' => [2, 3]];
$r = json_decode(json_encode($d), true);
echo $r['a'], ' ', $r['b'][1], "\n";
