--TEST--
stdlib filter_var() FILTER_VALIDATE_INT/FLOAT min_range/max_range (#13188, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var('5', FILTER_VALIDATE_INT, ['options' => ['min_range' => 10]]));
echo "\n";
var_export(filter_var('15', FILTER_VALIDATE_INT, ['options' => ['max_range' => 10]]));
echo "\n";
var_export(filter_var('10', FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 20]]));
echo "\n";
var_export(filter_var('1.5', FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 2.0]]));
echo "\n";
var_export(filter_var('2.5', FILTER_VALIDATE_FLOAT, ['options' => ['max_range' => 2.0]]));
echo "\n";
var_export(filter_var('2.0', FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 1.0, 'max_range' => 3.0]]));
echo "\n";
--EXPECT--
false
false
10
false
false
2.0
