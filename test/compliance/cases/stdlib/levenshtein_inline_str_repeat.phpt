--TEST--
stdlib levenshtein() — inline str_repeat() call arg with literal second operand (#10402, ext/standard/string.c)
--FILE--
<?php
var_dump(levenshtein(str_repeat('a', 260), 'b'));
--EXPECT--
int(260)
