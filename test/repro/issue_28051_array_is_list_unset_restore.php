<?php
// Repro #28051 — unset last list key then restore; Zend array_is_list true.
$a = [0 => 1, 1 => 2];
unset($a[1]);
$a[1] = 3;
var_export(array_is_list($a));
echo "\n";
$b = [1 => 1, 2 => 2];
var_export(array_is_list($b));
echo "\n";
