--TEST--
stdlib SortDirection phantom absent (#28930, re-#7261)
--FILE--
<?php
var_export(enum_exists('SortDirection', false));
echo "\n";
var_export(class_exists('SortDirection', false));
echo "\n";
--EXPECT--
false
false
