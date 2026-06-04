--TEST--
Language: unit enum cases stay objects — var_export and undefined value (#5731, zend_enum.c)
--FILE--
<?php
enum U {
    case A;
}
var_export(U::A);
echo "\n";
var_export(U::A->value);
echo "\n";
echo (U::A === U::A) ? "1" : "0";
echo "\n";
--EXPECT--
\U::A
NULL
1
