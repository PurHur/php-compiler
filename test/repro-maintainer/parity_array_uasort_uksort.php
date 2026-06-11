<?php

declare(strict_types=1);

var_export(function_exists('array_uasort'));
echo "\n";
var_export(function_exists('array_uksort'));
echo "\n";

$a = ['b' => 2, 'a' => 1];
array_uasort($a, static fn ($x, $y) => $x <=> $y);
var_export($a);
echo "\n";

$k = ['b' => 1, 'a' => 2];
array_uksort($k, static fn ($x, $y) => strcmp((string) $x, (string) $y));
var_export($k);
echo "\n";
