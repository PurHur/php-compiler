<?php
// Repro for #28930 / re-#7229 — Sorting phantom absent; array_multisort() ints.

echo 'Sorting enum: ', enum_exists('Sorting', false) ? 'yes' : 'no', "\n";

$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, SORT_ASC, $b);
echo 'asc: ', implode(',', $a), "\n";

$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, SORT_DESC, $b);
echo 'desc: ', implode(',', $a), "\n";
