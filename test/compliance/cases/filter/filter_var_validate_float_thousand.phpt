--TEST--
stdlib filter_var() FILTER_VALIDATE_FLOAT ALLOW_THOUSAND (#29013, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var('1,234.5', FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_THOUSAND));
echo "\n";
var_export(filter_var('1.234,5', FILTER_VALIDATE_FLOAT, [
    'options' => ['decimal' => ','],
    'flags' => FILTER_FLAG_ALLOW_THOUSAND,
]));
echo "\n";
var_export(filter_var('12,34.5', FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_THOUSAND));
echo "\n";
var_export(filter_var('1234.5', FILTER_VALIDATE_FLOAT));
echo "\n";
--EXPECT--
1234.5
1234.5
false
1234.5
