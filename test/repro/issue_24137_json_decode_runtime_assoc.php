<?php
$d = ['a' => 1, 'b' => [2, 3]];
$j = json_encode($d);
$r = json_decode($j, true);
echo $j, ' ', $r['a'], ' ', $r['b'][1], "\n";
