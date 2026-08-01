--TEST--
Language: property defaults fold self/parent/Named::class (#26629, #3803, zend_compile.c)
--FILE--
<?php
class B {}
class A extends B {
    public $x = self::class;
    public $y = parent::class;
    public $z = B::class;
    public static $sx = self::class;
    public static $sy = parent::class;
    public static $sz = B::class;
}
$a = new A;
echo $a->x, "|", $a->y, "|", $a->z, "\n";
echo A::$sx, "|", A::$sy, "|", A::$sz, "\n";
--EXPECT--
A|B|B
A|B|B
