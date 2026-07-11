--TEST--
stdlib filter_var() FILTER_SANITIZE_NUMBER_FLOAT int flags (#17410, ext/filter/sanitizing_filters.c)
--FILE--
<?php
$s = '1,234.5e2';
var_export(filter_var(
    $s,
    FILTER_SANITIZE_NUMBER_FLOAT,
    FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND | FILTER_FLAG_ALLOW_SCIENTIFIC
));
echo "\n";
var_export(filter_var('12.34', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION));
echo "\n";
--EXPECT--
'1,234.5e2'
'12.34'
