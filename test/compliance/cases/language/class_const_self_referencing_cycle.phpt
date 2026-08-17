--TEST--
Language: circular class constants throw Cannot declare self-referencing constant (#31837, zend_constants.c)
--FILE--
<?php
class A {
    public const X = self::Y;
    public const Y = self::X;
}
class B {
    public const OK = 1;
    public const X = self::Y;
    public const Y = self::X;
}
echo B::OK, "\n";
try {
    echo A::X;
    echo "no-error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo A::Y;
    echo "no-error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
class C {
    public const X = self::X;
}
try {
    echo C::X;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
1
Cannot declare self-referencing constant self::Y
Cannot declare self-referencing constant self::X
Cannot declare self-referencing constant self::X
