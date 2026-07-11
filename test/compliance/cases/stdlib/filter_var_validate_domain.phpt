--TEST--
stdlib filter_var() FILTER_VALIDATE_DOMAIN (#17407, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var('example.com', FILTER_VALIDATE_DOMAIN));
echo "\n";
var_export(filter_var('example..com', FILTER_VALIDATE_DOMAIN));
echo "\n";
var_export(filter_var('.com', FILTER_VALIDATE_DOMAIN));
echo "\n";
--EXPECT--
'example.com'
false
false

