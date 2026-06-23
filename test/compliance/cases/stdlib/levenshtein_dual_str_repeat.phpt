--TEST--
stdlib levenshtein() — dual inline str_repeat() call args (#10917, ext/standard/string.c)
--FILE--
<?php
$n = 100;
var_dump(levenshtein(str_repeat('a', $n), str_repeat('b', $n)));
--EXPECT--
int(100)
