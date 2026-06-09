<?php
// Repro for #7229 — Sorting enum + array_multisort() (ext/standard/basic_functions.stub.php).

echo 'Sorting enum: ', enum_exists('Sorting', false) ? 'yes' : 'no', "\n";

$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, $b, Sorting::Ascending);
echo 'asc: ', implode(',', $a), "\n";

$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, $b, Sorting::Descending);
echo 'desc: ', implode(',', $b), "\n";
