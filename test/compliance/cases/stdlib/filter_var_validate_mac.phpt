--TEST--
stdlib filter_var() FILTER_VALIDATE_MAC (#17411, ext/filter/logical_filters.c)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var('00:00:5e:00:53:af', FILTER_VALIDATE_MAC));
echo "\n";
var_export(filter_var('FA-F9-DD-B2-5E-0D', FILTER_VALIDATE_MAC));
echo "\n";
var_export(filter_var('not-a-mac', FILTER_VALIDATE_MAC));
echo "\n";
var_export(filter_var('', FILTER_VALIDATE_MAC));
echo "\n";
--EXPECT--
'00:00:5e:00:53:af'
'FA-F9-DD-B2-5E-0D'
false
false
