--TEST--
Language: backed enum case ->value in property/static/parameter defaults (#7399, zend_compile.c)
--FILE--
<?php
enum E: int { case A = 1; }

class C {
    public int $n = E::A->value;
    public static int $s = E::A->value;
}

function f(int $n = E::A->value): int { return $n; }

var_dump((new C())->n, C::$s, f());
--EXPECT--
int(1)
int(1)
int(1)
