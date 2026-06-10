--TEST--
stdlib SortDirection enum — pure enum cases (#7261)
--FILE--
<?php
var_export(enum_exists('SortDirection', false));
echo "\n";
var_export(unitenum_exists('SortDirection'));
echo "\n";
var_export(SortDirection::Ascending->name);
echo "\n";
var_export(SortDirection::Descending->name);
echo "\n";
--EXPECT--
true
true
'Ascending'
'Descending'
