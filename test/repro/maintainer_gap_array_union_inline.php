<?php

declare(strict_types=1);

var_export([1 => 'a'] + [2 => 'b']);
echo "\n";
var_export(['a' => 1] + ['a' => 2]);
echo "\n";
var_export([] + [1]);
echo "\n";

$a = ['x' => 1];
$b = ['y' => 2];
var_export($a + $b);
echo "\n";
