--TEST--
Language: parenthesized bare-name bitwise AND is expr, not intersection type (#24131, Zend/zend_language_parser.y)
--FILE--
<?php
echo (E_ERROR & E_WARNING), "\n";
echo (E_ALL & E_WARNING) !== 0 ? "y\n" : "n\n";
var_export(E_ERROR & E_WARNING);
echo "\n";
const A = 1;
const B = 2;
echo (A & B), "\n";
echo (1 & 2), "\n";
echo (PHP_VERSION_ID & 255) > 0 ? "ok\n" : "bad\n";
--EXPECT--
0
y
0
0
0
ok
