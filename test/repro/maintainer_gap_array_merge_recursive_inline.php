<?php

declare(strict_types=1);

var_export(array_merge_recursive(['a' => 1], ['b' => 2]));
echo "\n";
var_export(array_merge_recursive(['color' => 'red'], ['color' => 'green']));
echo "\n";
var_export(array_merge_recursive(['a' => ['x' => 1]], ['a' => ['y' => 2]]));
echo "\n";
