<?php
declare(strict_types=1);

$a = ['a' => 1, 'b' => 2, 'c' => 1];
var_export(array_keys($a, 1));
echo "\n";
var_export(array_keys($a, '1', true));
echo "\n";
var_export(array_keys($a, '1', false));
echo "\n";
