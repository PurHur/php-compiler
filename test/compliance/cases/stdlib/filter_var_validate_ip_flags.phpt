--TEST--
stdlib filter_var() FILTER_VALIDATE_IP flags (issue #10374, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);

var_export(filter_var('::1', FILTER_VALIDATE_IP, FILTER_FLAG_IPV6));
echo "\n";
var_export(filter_var('::1', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));
echo "\n";
var_export(filter_var('192.168.0.1', FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE));
echo "\n";
--EXPECT--
'::1'
false
false

