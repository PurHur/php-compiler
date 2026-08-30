<?php
// #36002 — NestedJIT findAt inner while miscompares string dims
echo str_replace('xy', 'zw', 'xy!'), "\n";
echo str_ireplace('XY', 'zw', 'xy!'), "\n";
$r = ['a' => 'xy', 'b' => 'zw'];
echo str_replace($r['a'], $r['b'], 'xy!'), "\n";
