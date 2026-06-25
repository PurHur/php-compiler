<?php

declare(strict_types=1);

$a = [1, 2];
array_walk($a, fn (&$v) => $v++);
var_export($a);
echo "\n";

$b = [1 => [2]];
array_walk_recursive($b, fn (&$v) => $v++);
var_export($b);
echo "\n";
