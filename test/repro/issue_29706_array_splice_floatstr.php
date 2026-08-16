<?php
ini_set('error_reporting', E_ALL);
$a = [1, 2, 3, 4];
array_splice($a, '1.5', 1);
var_export($a);
echo "\n";
$b = [1, 2, 3, 4];
array_splice($b, 1.5, 1);
var_export($b);
echo "\n";
