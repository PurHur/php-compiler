--TEST--
stdlib pack() JIT on backed enum — E_WARNING + backing int (#5713, #6213)
--JIT--
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

$data = @pack('c', E::A);
echo 'c ', bin2hex($data), "\n";
@pack('c', E::A);
$err = error_get_last();
echo 'warning: ', $err['message'] ?? '', "\n";
--EXPECT--
c 01
warning: Object of class E could not be converted to int
