<?php
// Repro for #23951 — AOT max()/min() on strings must match Zend (not int 0).
$a = 'xy';
$b = 'zw';
echo max($a, $b), "\n";
echo min($a, $b), "\n";
echo max($a, $b, 'zz'), "\n";
echo min('aa', $a, $b), "\n";
$r = ['a' => 'xy', 'b' => 'zw'];
echo max($r['a'], $r['b']), "\n";
echo min($r['a'], $r['b']), "\n";
echo max(3, 7), "\n";
