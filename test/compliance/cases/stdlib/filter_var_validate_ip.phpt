--TEST--
stdlib filter_var() FILTER_VALIDATE_IP (#4403, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var('127.0.0.1', FILTER_VALIDATE_IP));
echo "\n";
var_export(filter_var('::1', FILTER_VALIDATE_IP));
echo "\n";
var_export(filter_var('not-an-ip', FILTER_VALIDATE_IP));
echo "\n";
var_export(filter_var('999.999.999.999', FILTER_VALIDATE_IP));
echo "\n";
--EXPECT--
'127.0.0.1'
'::1'
false
false
