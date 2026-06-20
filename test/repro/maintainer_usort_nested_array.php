<?php
$a = [['b', 1], ['a', 1]];
usort($a, fn($x, $y) => strcmp($x[0], $y[0]));
var_export($a);
echo "\n";
