--TEST--
Language: backed enum scalar casts — VM/JIT parity legacy 1 (#7120, zend_operators.c)
--FILE--
<?php
enum E: int
{
    case FortyTwo = 42;
}

echo 'int: ', @(int) E::FortyTwo, "\n";
echo 'intval: ', @intval(E::FortyTwo), "\n";
echo 'float: ', @(float) E::FortyTwo, "\n";
echo 'floatval: ', @floatval(E::FortyTwo), "\n";
?>
--EXPECT--
int: 1
intval: 1
float: 1
floatval: 1
