<?php
// sort()/rsort() parity with php-src zend_sort + stable tiebreak: ints (radix path), non-numeric
// strings (prefix merge path), numeric strings, non-transitive mixed arrays (generic zend_sort).
$a = [5, 3, 9, 1, 5, -2, 0, 100, 7]; sort($a); echo implode(',', $a), "\n";
rsort($a); echo implode(',', $a), "\n";
$s = ["pear", "apple", "Fig", "banana", "apple", "cherry"]; sort($s); echo implode(',', $s), "\n";
rsort($s); echo implode(',', $s), "\n";
$m = [3, "10", 2.5, "abc", true, null, "9"]; sort($m); var_dump($m);
$e = []; sort($e); var_dump($e); $one = [42]; sort($one); var_dump($one);
$big = []; for ($i = 0; $i < 1000; ++$i) { $big[] = ($i * 7919) % 1009; } sort($big); echo $big[0], ' ', $big[500], ' ', $big[999], ' ', array_sum($big), "\n";
$f = [3.5, -1.25, 2.0, 10.0]; sort($f); echo implode(',', $f), "\n";
$cs = ["b", "B", "a", "A"]; sort($cs, SORT_STRING | SORT_FLAG_CASE); echo implode(',', $cs), "\n";
$ns = ["10", "9", "1e1", "abc", "2", " 3"]; sort($ns); var_dump($ns);
$mx = []; for ($i = 0; $i < 40; ++$i) { if ($i % 3 == 0) { $mx[] = (string)($i * 7 % 13); } elseif ($i % 3 == 1) { $mx[] = $i * 5 % 11; } else { $mx[] = "x" . ($i % 4); } } sort($mx); echo json_encode($mx), "\n"; rsort($mx); echo json_encode($mx), "\n";
$fl = [1.5, "1.5", 1, true, "1"]; sort($fl); var_dump($fl);
$ri = []; for ($i = 0; $i < 3000; ++$i) { $ri[] = (($i * 2654435761) % 4001) - 2000; } $ri[] = PHP_INT_MAX; $ri[] = PHP_INT_MIN; sort($ri); echo $ri[0], ' ', $ri[1], ' ', $ri[1500], ' ', $ri[3001], "\n"; rsort($ri); echo $ri[0], ' ', $ri[2], ' ', $ri[3001], "\n";
$rs = []; for ($i = 0; $i < 2000; ++$i) { $rs[] = 'k' . (($i * 7919) % 997); } sort($rs); echo $rs[0], ' ', $rs[999], ' ', $rs[1999], "\n"; rsort($rs); echo $rs[0], ' ', $rs[1999], "\n";
$num = ["10", "9", "100", "x"]; sort($num); echo implode(',', $num), "\n";
