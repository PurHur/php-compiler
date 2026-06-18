--TEST--
Language: class const array subscript in scalar expr (#5465, zend_compile.c)
--FILE--
<?php
class C {
    public const ARR = [10, 20, 30];
    public const X = self::ARR[1];
    public const Y = C::ARR[2];
    public const Z = [1, 2][0];
}
echo C::X, "\n", C::Y, "\n", C::Z, "\n";
--EXPECT--
20
30
1
