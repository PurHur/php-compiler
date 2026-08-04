<?php
// #27521 — AOT array_intersect_key must keep shared keys (not empty).
$r = array_intersect_key(["a" => 1, "b" => 2, "c" => 3], ["a" => 9, "c" => 8]);
echo "keys=", implode(",", array_keys($r)), " vals=", implode(",", array_values($r)), "\n";
$a = ["a" => 1, "b" => 2, "c" => 3];
$b = ["a" => 9, "c" => 8];
// Force materialization (thin-AOT const-local boxes) then intersect.
count($a);
count($b);
$r2 = array_intersect_key($a, $b);
echo "vars keys=", implode(",", array_keys($r2)), " vals=", implode(",", array_values($r2)), "\n";
