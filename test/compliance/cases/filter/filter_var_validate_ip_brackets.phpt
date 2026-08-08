--TEST--
filter_var() FILTER_VALIDATE_IP rejects bracketed IPv6 (#29063, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);

var_export(filter_var('[::1]', FILTER_VALIDATE_IP));
echo "\n";
var_export(filter_var('[::1]', FILTER_VALIDATE_IP, FILTER_FLAG_IPV6));
echo "\n";
var_export(filter_var('[2001:db8::1]', FILTER_VALIDATE_IP));
echo "\n";
var_export(filter_var('::1', FILTER_VALIDATE_IP));
echo "\n";
var_export(filter_var('2001:db8::1', FILTER_VALIDATE_IP));
echo "\n";
--EXPECT--
false
false
false
'::1'
'2001:db8::1'
