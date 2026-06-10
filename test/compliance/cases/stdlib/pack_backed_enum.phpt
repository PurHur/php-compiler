--TEST--
stdlib pack() on backed enum — E_WARNING + backing int (#5713, #6213, ext/standard/pack.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
    case B = 42;
}

$data = @pack('c', E::A);
echo 'c ', bin2hex($data), "\n";
$data2 = @pack('c', E::B);
echo 'c42 ', bin2hex($data2), "\n";
@pack('c', E::A);
$err = error_get_last();
echo 'warning: ', $err['message'] ?? '', "\n";

$r = unpack('c', $data);
echo 'roundtrip ', $r[1], "\n";
--EXPECT--
c 01
c42 2a
warning: Object of class E could not be converted to int
roundtrip 1
