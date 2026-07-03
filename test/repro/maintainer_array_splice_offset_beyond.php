<?php

declare(strict_types=1);

$a = [1, 2, 3];
$removed = array_splice($a, 10, 0, [99]);
var_export([$a, $removed]);
echo "\n";

$b = [1, 2];
$removedB = array_splice($b, 5, 0, [9]);
var_export([$b, $removedB]);
echo "\n";
