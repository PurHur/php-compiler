--TEST--
Language: multi-byte string offset assignment E_WARNING (#22380, Zend/zend_execute.c)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');
$s = 'abc';
$s[1] = 'XYZ';
echo $s, "\n";
$t = 'abc';
$t[1] = 'X';
echo $t, "\n";
--EXPECT--
W:Only the first byte will be assigned to the string offset
aXc
aXc
