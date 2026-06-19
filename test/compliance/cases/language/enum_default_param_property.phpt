--TEST--
Language: enum typed parameter and instance property defaults preserve case singleton (#9895, re-#9715, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }

function f(E $e = E::A): E {
    return $e;
}

class P {
    public E $e = E::A;
}

var_export([f()->name, (new P())->e->name]);
echo (f() === E::A && (new P())->e === E::A) ? "same\n" : "diff\n";
--EXPECT--
array (
  0 => 'A',
  1 => 'A',
)same
