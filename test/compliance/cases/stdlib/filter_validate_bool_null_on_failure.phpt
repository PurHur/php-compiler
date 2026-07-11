--TEST--
stdlib filter_var() FILTER_VALIDATE_BOOL null coerces to false with FILTER_NULL_ON_FAILURE (#17238)
--FILE--
<?php
var_export(filter_var(null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE));
echo "\n";
var_export(filter_var('', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE));
echo "\n";
var_export(filter_var('not-bool', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE));
echo "\n";
?>
--EXPECT--
false
false
NULL
