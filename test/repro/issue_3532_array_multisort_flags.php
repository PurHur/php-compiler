<?php
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, SORT_ASC, SORT_NUMERIC, $b, SORT_ASC, SORT_STRING);
echo implode(',', $a), '|', implode(',', $b), "\n";

$d = [3, 1, 2];
$c = ['z', 'x', 'y'];
array_multisort($d, SORT_DESC, $c);
echo implode(',', $d), "\n";
echo implode(',', $c), "\n";
