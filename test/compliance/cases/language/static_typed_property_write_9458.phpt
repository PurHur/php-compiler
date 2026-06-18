--TEST--
Language: typed static property assignment round-trip (#9458)
--FILE--
<?php
class IntBox {
    public static int $n = 1;
}
var_dump(IntBox::$n);
IntBox::$n = 2;
var_dump(IntBox::$n);

class StrBox {
    public static string $s = 'a';
}
var_dump(StrBox::$s);
StrBox::$s = 'b';
var_dump(StrBox::$s);

class FloatBox {
    public static float $f = 1.5;
}
var_dump(FloatBox::$f);
FloatBox::$f = 2.5;
var_dump(FloatBox::$f);

class NullableInt {
    public static ?int $x = 3;
}
var_dump(NullableInt::$x);
NullableInt::$x = null;
var_dump(NullableInt::$x);

class Untyped {
    public static $n = 1;
}
var_dump(Untyped::$n);
Untyped::$n = 2;
var_dump(Untyped::$n);

class InstanceTyped {
    public int $n = 1;
}
$o = new InstanceTyped();
var_dump($o->n);
$o->n = 2;
var_dump($o->n);
--EXPECT--
int(1)
int(2)
string(1) "a"
string(1) "b"
float(1.5)
float(2.5)
int(3)
NULL
int(1)
int(2)
int(1)
int(2)
