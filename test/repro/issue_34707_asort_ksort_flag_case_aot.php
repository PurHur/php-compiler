<?php
// #34707 — AOT asort/arsort/ksort/krsort must honor SORT_STRING|SORT_FLAG_CASE (peer #34702)
$a = ['x' => 'B', 'y' => 'a', 'z' => 'C'];
asort($a, SORT_STRING | SORT_FLAG_CASE);
echo 'asort_case:', json_encode(array_values($a)), "\n";

$b = ['x' => 'B', 'y' => 'a', 'z' => 'C'];
arsort($b, SORT_STRING | SORT_FLAG_CASE);
echo 'arsort_case:', json_encode(array_values($b)), "\n";

$c = ['B' => 1, 'a' => 2, 'C' => 3];
ksort($c, SORT_STRING | SORT_FLAG_CASE);
echo 'ksort_case:', json_encode(array_keys($c)), "\n";

$d = ['B' => 1, 'a' => 2, 'C' => 3];
krsort($d, SORT_STRING | SORT_FLAG_CASE);
echo 'krsort_case:', json_encode(array_keys($d)), "\n";
