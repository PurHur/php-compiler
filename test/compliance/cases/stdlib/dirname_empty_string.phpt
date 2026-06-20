--TEST--
Stdlib: dirname('') empty path — returns '' not '.' (#10258, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(dirname(''));
echo "\n";
var_export(dirname('a'));
echo "\n";
var_export(dirname('/a/b'));
echo "\n";
--EXPECT--
''
'.'
'/a'
