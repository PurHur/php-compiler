--TEST--
Language: parenthesized nested ternary forms match Zend (#20737, Zend/zend_language_parser.y)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo (true ? "a" : false) ? "b" : "c", "\n";
echo true ? "a" : (false ? "b" : "c"), "\n";
echo true ? false ? "b" : "c" : "a", "\n";
echo 0 ?: 0 ?: "x", "\n";
--EXPECT--
b
a
c
x
