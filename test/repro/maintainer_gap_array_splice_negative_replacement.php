<?php
declare(strict_types=1);

$a = [0, 1, 2, 3, 4];
array_splice($a, -2, 1, ['x']);
var_export($a);
echo "\n";

$b = [0, 1, 2, 3, 4];
array_splice($b, 2, 1, ['x']);
var_export($b);
echo "\n";

$c = [0, 1, 2, 3, 4];
array_splice($c, -2, 1);
var_export($c);
echo "\n";
