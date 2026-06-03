--TEST--
Language: compound += on int with non-numeric string — E_WARNING and int result (zend_operators.c, #4892)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');
$a = 1;
$a += '2abc';
var_export($a);
echo "\n";
$b = 10;
$b -= '2abc';
var_export($b);
echo "\n";
$c = 2;
$c *= '3abc';
var_export($c);
echo "\n";
$d = 10;
$d /= '2abc';
var_export($d);
echo "\n";
--EXPECT--
W:A non-numeric value encountered
3
W:A non-numeric value encountered
8
W:A non-numeric value encountered
6
W:A non-numeric value encountered
5
