<?php
// #27522 — AOT array_diff_key must keep keys absent from others (not empty).
$r = array_diff_key(["a" => 1, "b" => 2, "c" => 3], ["a" => 9]);
echo "keys=", implode(",", array_keys($r)), " vals=", implode(",", array_values($r)), "\n";
$a = ["a" => 1, "b" => 2, "c" => 3];
$b = ["a" => 9];
count($a);
count($b);
$r2 = array_diff_key($a, $b);
echo "vars keys=", implode(",", array_keys($r2)), " vals=", implode(",", array_values($r2)), "\n";
