--TEST--
Language: int RHS to string offset — stringify then first byte (#25778, Zend/zend_execute.c)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');
$s = 'abc';
$s[1] = 65;
var_export($s);
echo "\n";
$t = 'abc';
$t[1] = 0;
var_export($t);
echo "\n";
$u = 'abc';
$u[1] = 5;
var_export($u);
echo "\n";
$v = 'abc';
$v[1] = -1;
var_export($v);
echo "\n";
$w = 'abc';
$w[1] = 'XY';
var_export($w);
echo "\n";
--EXPECT--
W:Only the first byte will be assigned to the string offset
'a6c'
'a0c'
'a5c'
W:Only the first byte will be assigned to the string offset
'a-c'
W:Only the first byte will be assigned to the string offset
'aXc'
