--TEST--
Language: null coalescing on defined non-null operands (??)
--FILE--
<?php
var_export([] ?? 1);
echo "\n";
echo is_object((object) [] ?? 1) ? "(object) array(\n)\n" : "1\n";
var_export(false ?? 1);
echo "\n";
var_export(0 ?? 1);
echo "\n";
--EXPECT--
array (
)
(object) array(
)
false
0
