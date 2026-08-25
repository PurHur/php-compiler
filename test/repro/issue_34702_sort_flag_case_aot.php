<?php
// #34702 — AOT sort/rsort must honor SORT_STRING|SORT_FLAG_CASE (php-src array.c)
$a = ['B', 'a', 'C'];
rsort($a, SORT_STRING | SORT_FLAG_CASE);
echo 'rsort_case:', json_encode($a), "\n";
$b = ['B', 'a', 'C'];
rsort($b, SORT_STRING);
echo 'rsort_str:', json_encode($b), "\n";
$c = ['B', 'a', 'C'];
sort($c, SORT_STRING | SORT_FLAG_CASE);
echo 'sort_case:', json_encode($c), "\n";
