<?php
declare(strict_types=1);

$a = ['b' => 2, 'a' => 1];
array_uasort($a, static fn(int $x, int $y): int => $x <=> $y);
var_export($a);
echo "\n";
