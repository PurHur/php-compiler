<?php

declare(strict_types=1);

var_export(array_replace_recursive(['a' => ['b' => 1]], ['a' => null]));
echo "\n";

$a = ['a' => ['b' => 1]];
$b = ['a' => null];
var_export(array_replace_recursive($a, $b));
echo "\n";
