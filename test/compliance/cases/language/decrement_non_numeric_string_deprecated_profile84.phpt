--TEST--
Decrement non-numeric string emits Zend E_DEPRECATED under PROFILE=8.4 (#29088)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $s): bool {
    echo 'D:', $s, "\n";
    return true;
});
$s = 'A';
$s--;
echo "VAL=$s\n";
$t = 'Z';
--$t;
echo "VAL=$t\n";
$n = '10';
$n--;
echo "NUM=$n\n";
$u = 'A';
$u++;
echo "INC=$u\n";
--EXPECT--
D:Decrement on non-numeric string has no effect and is deprecated
VAL=A
D:Decrement on non-numeric string has no effect and is deprecated
VAL=Z
NUM=9
INC=B
