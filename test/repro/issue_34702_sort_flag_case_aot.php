<?php
$a = ['B', 'a', 'C'];
sort($a, SORT_STRING | SORT_FLAG_CASE);
echo json_encode($a), "\n";
$b = ['B', 'a', 'C'];
rsort($b, SORT_STRING | SORT_FLAG_CASE);
echo json_encode($b), "\n";
$c = ['B', 'a', 'C'];
sort($c, SORT_REGULAR | SORT_FLAG_CASE);
echo json_encode($c), "\n";
$d = ['B', 'a', 'C'];
rsort($d, SORT_REGULAR | SORT_FLAG_CASE);
echo json_encode($d), "\n";
