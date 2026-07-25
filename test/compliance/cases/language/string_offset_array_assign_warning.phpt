--TEST--
Language: array RHS to string offset emits Array to string conversion (#22925, Zend/zend_execute.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});

$s = 'ab';
$s[0] = [];
echo $s, "\n";

$t = 'ab';
$t[0] = ['x' => 1];
echo $t, "\n";
--EXPECT--
W:Array to string conversion
W:Only the first byte will be assigned to the string offset
Ab
W:Array to string conversion
W:Only the first byte will be assigned to the string offset
Ab
