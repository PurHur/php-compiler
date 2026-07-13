--TEST--
stdlib strpos()/substr_count() null haystack — false/0 not TypeError (#18674, ext/standard/string.c)
--FILE--
<?php
var_export(strpos(null, 'x'));
echo "\n";
var_export(substr_count(null, 'x'));
echo "\n";
?>
--EXPECT--
false
0
