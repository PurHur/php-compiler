--TEST--
stdlib setlocale(null $category) soft DEP + set (#31487, ext/standard/string.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
var_export(setlocale(null, 'C'));
echo "\n";
?>
--EXPECTF--
%ADeprecated: setlocale(): Passing null to parameter #1 ($category) of type int is deprecated in %s on line %d
'C'
