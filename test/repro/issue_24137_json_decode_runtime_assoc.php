<?php
// @differential-repeat: 5 AOT runtime json_decode hashtable (#24137)
$d = ['a' => 1, 'b' => [2, 3]];
$j = json_encode($d);
$r = json_decode($j, true);
echo $r['a'], ' ', $r['b'][1], "\n";
