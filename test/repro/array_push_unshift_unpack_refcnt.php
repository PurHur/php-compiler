<?php
declare(strict_types=1);

$a = [1, 2];
array_unshift($a, ...[3, 4]);
var_export($a);
echo "\n";

$b = [1, 2];
array_push($b, ...[3, 4]);
var_export($b);
echo "\n";
