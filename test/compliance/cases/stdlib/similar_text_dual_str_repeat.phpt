--TEST--
stdlib similar_text() — dual inline str_repeat() disjoint long strings (#10918, ext/standard/string.c)
--FILE--
<?php
$n = 100;
var_dump(similar_text(str_repeat('a', $n), str_repeat('b', $n)));
--EXPECT--
int(0)
