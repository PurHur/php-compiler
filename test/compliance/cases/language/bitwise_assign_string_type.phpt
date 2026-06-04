--TEST--
Language: compound &= on int with partial numeric string — E_WARNING and int result (zend_operators.c, #5428)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');
$a = 5;
$a &= '2x';
var_export($a);
echo ' ', gettype($a), "\n";
$b = 7;
$b |= '3y';
var_export($b);
echo ' ', gettype($b), "\n";
$c = 10;
$c ^= '2z';
var_export($c);
echo ' ', gettype($c), "\n";
--EXPECT--
W:A non-numeric value encountered
0 integer
W:A non-numeric value encountered
7 integer
W:A non-numeric value encountered
8 integer
