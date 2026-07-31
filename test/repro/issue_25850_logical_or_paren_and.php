<?php
// #25850 — false || (is_array($a) && …) must be bool, not callee-name string
$a = ["a", "b"];
var_export(false || (is_array($a) && false));
echo "\n";
var_export(false || (is_array($a) && true));
echo "\n";
var_export(false || (count($a) === 2 && false));
echo "\n";
