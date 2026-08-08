<?php
// Issue #29145 — `$a ??= []; $a["k"] ??= v` must mutate the live CV (Zend/zend_execute.c).
$a ??= [];
$a["x"] ??= 1;
var_export($a);
echo "\n";
unset($a);
$a ??= ["x" => 0];
$a["x"] ??= 1;
$a["y"] ??= 2;
var_export($a);
echo "\n";
