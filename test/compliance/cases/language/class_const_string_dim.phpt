--TEST--
Language: string dimension fetch in class constant expressions (#24927, zend_compile.c)
--FILE--
<?php
class C {
    public const X = "ab"[0];
    public const Y = "hello"[1];
    public const Z = "xy"[-1];
    public const W = "ab"["0"];
}
echo C::X, "\n", C::Y, "\n", C::Z, "\n", C::W, "\n";
--EXPECT--
a
e
y
a
