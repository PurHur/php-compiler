<?php
$a = ['k' => ['x' => 1, 'y' => 2]];
$b = ['k' => ['y' => 9]];
var_export(array_replace_recursive($a, $b));
echo "\n";
