--TEST--
stdlib filter_var() FILTER_VALIDATE_BOOLEAN and FILTER_VALIDATE_FLOAT (#4742)
--FILE--
<?php
var_export(filter_var(true, FILTER_VALIDATE_BOOLEAN));
echo "\n";
var_export(filter_var(3.14, FILTER_VALIDATE_FLOAT));
echo "\n";
var_export(filter_var('no', FILTER_VALIDATE_BOOLEAN));
echo "\n";
--EXPECT--
true
3.14
false
