--TEST--
stdlib pack() — backed enum case numeric operand warns + packs backing int (#16397, ext/standard/pack.c)
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
echo 'warning: ', isset($err['message']) ? $err['message'] : '', "\n";

$data3 = @pack('i', E::A);
echo 'i ', strlen($data3), "\n";

$r = unpack('c', $data);
echo 'roundtrip ', $r[1], "\n";
--EXPECT--
c 01
c42 2a
warning: Object of class E could not be converted to int
i 4
roundtrip 1
