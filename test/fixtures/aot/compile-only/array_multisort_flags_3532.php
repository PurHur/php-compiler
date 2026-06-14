<?php
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, SORT_ASC, SORT_NUMERIC, $b, SORT_ASC, SORT_STRING);
echo implode(',', $a), '|', implode(',', $b), "\n";
