--TEST--
Language: unary + on numeric-prefix string — E_WARNING and parsed prefix (zend_operators.c, #5427)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');
var_export(+'5x');
echo "\n";
var_export(+'12abc');
echo "\n";
--EXPECT--
W:A non-numeric value encountered
5
W:A non-numeric value encountered
12
