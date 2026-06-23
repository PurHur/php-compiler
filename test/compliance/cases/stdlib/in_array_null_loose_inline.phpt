--TEST--
stdlib in_array()/array_search() null needle loose match on inline [null] haystack (#10909, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
var_export(in_array(null, [null]));
echo "\n";
var_export(in_array(null, [null], true));
echo "\n";
var_export(array_search(null, [null]));
echo "\n";
?>
--EXPECT--
true
true
0
