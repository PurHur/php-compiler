--TEST--
ctype_* named text argument (JIT, issue #23192)
--FILE--
<?php
var_export(ctype_digit(text: '123'));
echo PHP_EOL;
var_export(ctype_alnum(text: 'A1'));
echo PHP_EOL;
--EXPECT--
true
true
