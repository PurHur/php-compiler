--TEST--
Class constant scalar expressions (issue #3567)
--FILE--
<?php
class C {
    public const X = 1 + 2;
    public const Y = self::class;
    public const Z = __CLASS__;
    public const A = 10;
    public const B = self::A + 5;
}
echo C::X, "\n", C::Y, "\n", C::Z, "\n", C::B, "\n";
--EXPECT--
3
C
C
15
