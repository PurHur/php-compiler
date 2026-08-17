--TEST--
Circular class constants throw Cannot declare self-referencing constant (#31837, zend_constants.c)
--FILE--
<?php
class A {
    public const X = self::Y;
    public const Y = self::X;
}
try {
    echo A::X;
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

class B {
    public const FOO = self::FOO;
}
try {
    echo B::FOO;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

// Non-circular forward ref still resolves (#7382).
class C {
    public const A = self::B + 1;
    public const B = 1;
}
echo C::A, "\n";
--EXPECT--
Error:Cannot declare self-referencing constant self::Y
Error:Cannot declare self-referencing constant self::FOO
2
