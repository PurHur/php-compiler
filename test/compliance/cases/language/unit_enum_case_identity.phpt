--TEST--
Language: unit enum cases stay objects — var_export and undefined value (#5731, #22523, zend_enum.c)
--FILE--
<?php
error_reporting(E_ALL);
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

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
W:Undefined property: U::$value
NULL
1
