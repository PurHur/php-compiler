--TEST--
stdlib str_ireplace() array $search (issue #10599, ext/standard/string.c)
--FILE--
<?php
$c = 0;
var_export(str_ireplace(['a', 'b'], 'X', 'AbBa', $c));
echo ' count=', $c, "\n";
$c = 0;
var_export(str_ireplace(['a', 'b'], ['1', '2'], 'AbBa', $c));
echo ' count=', $c, "\n";
?>
--EXPECT--
'XXXX' count=4
'1221' count=4
