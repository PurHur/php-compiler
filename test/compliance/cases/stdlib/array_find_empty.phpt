--TEST--
stdlib array_find()/array_find_key() empty array returns NULL (#12519, #19118, ext/standard/array.c)
--FILE--
<?php
var_export(array_find([], static fn ($v) => true));
echo "\n";
var_export(array_find_key([], static fn ($v) => true));
echo "\n";
var_export(array_find([1, 2, 3], static fn ($v) => 2 === $v));
echo "\n";
?>
--EXPECT--
NULL
NULL
2
