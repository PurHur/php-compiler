--TEST--
stdlib filter_var() FILTER_VALIDATE_FLOAT options[decimal] (#29007, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);
$opts = ['options' => ['decimal' => ',']];
var_export(filter_var('1,5', FILTER_VALIDATE_FLOAT, $opts));
echo "\n";
var_export(filter_var('1.5', FILTER_VALIDATE_FLOAT, $opts));
echo "\n";
var_export(filter_var('11,0', FILTER_VALIDATE_FLOAT, $opts));
echo "\n";
var_export(filter_var('1.5', FILTER_VALIDATE_FLOAT));
echo "\n";
var_export(filter_var('0,5', FILTER_VALIDATE_FLOAT, [
    'options' => ['decimal' => ',', 'min_range' => 1],
]));
echo "\n";
--EXPECT--
1.5
false
11.0
1.5
false
