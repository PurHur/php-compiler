<?php
// Issue #4945 — array_multisort() single-array form (ext/standard/array.c).
$a = [3, 1, 2];
array_multisort($a);
echo json_encode($a), PHP_EOL;
$b = [3, 1, 2];
array_multisort($b, SORT_DESC);
echo json_encode($b), PHP_EOL;
