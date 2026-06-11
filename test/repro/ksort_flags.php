<?php
$a = ["b" => 1, "a" => 2];
ksort($a, SORT_STRING);
echo implode(",", array_keys($a)), "\n";
$b = [3, 1, 2];
asort($b, SORT_NUMERIC);
echo implode(",", $b), "\n";
