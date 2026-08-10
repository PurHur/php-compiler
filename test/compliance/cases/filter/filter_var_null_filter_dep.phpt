--TEST--
filter_var null $filter Deprecated then Unknown filter Warning (#29723)
--FILE--
<?php
ini_set('error_reporting', (string) E_ALL);
var_export(filter_var('1', null));
echo "\n";
?>
--EXPECTF--
%ADeprecated%Afilter_var(): Passing null to parameter #2 ($filter) of type int is deprecated%A
%AWarning%Afilter_var(): Unknown filter with ID 0%A
false
