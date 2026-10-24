<?php
// usort() parity: php_array_user_compare (zval_get_long, stable tiebreak) on exact zend_sort; user function name callbacks; array_map with a user function name.
function bylen($a, $b) { return strlen($a) <=> strlen($b); }
function dbl($x) { return $x * 2; }
$w = ["ccc", "a", "bb", "dddd", "e"]; usort($w, 'bylen'); echo implode(',', $w), "\n";
$w = ["ccc", "a", "bb", "dddd", "e"]; usort($w, fn($x, $y) => strlen($y) - strlen($x)); echo implode(',', $w), "\n";
$f = [3, 1, 2, 5, 4]; usort($f, fn($a, $b) => ($a - $b) / 10); echo implode(',', $f), "\n";
$big = []; for ($i = 0; $i < 500; ++$i) { $big[] = ($i * 31) % 97; } usort($big, fn($a, $b) => $a <=> $b); echo $big[0], ' ', $big[250], ' ', $big[499], "\n";
$o = []; for ($i = 0; $i < 30; ++$i) { $o[] = ['k' => $i % 7, 'id' => $i]; } usort($o, fn($a, $b) => $a['k'] <=> $b['k']); echo implode(',', array_map(fn($r) => $r['id'], $o)), "\n";
echo implode(',', array_map('dbl', [1, 2, 3])), "\n";
