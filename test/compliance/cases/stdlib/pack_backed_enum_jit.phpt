--TEST--
stdlib pack() JIT on backed enum — E_WARNING + backing int (#5713, #6213)
--JIT--
--ENV--
PHP_COMPILER_RUNTIME_STRICT=php-compiler
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
echo 'warning: ', isset($err['message']) ? $err['message'] : '', "\n";

$data2 = @pack('i', E::A);
echo 'i ', strlen($data2), "\n";
--EXPECT--
c 01
warning: Object of class E could not be converted to int
i 4
