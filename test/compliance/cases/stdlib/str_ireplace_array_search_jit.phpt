--TEST--
stdlib str_ireplace() array $search JIT (issue #10599, ext/standard/string.c)
--FILE--
<?php
$c = 0;
var_export(str_ireplace(['a', 'b'], 'X', 'AbBa', $c));
echo ' count=', $c, "\n";
?>
--EXPECT--
'XXXX' count=4
