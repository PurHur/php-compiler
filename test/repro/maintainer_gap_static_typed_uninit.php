<?php
/**
 * Repro for #12056 — static typed property read before initialization (Zend/zend_type.c).
 */
class C {
    public static int $x;
}
try {
    echo C::$x;
    echo "read_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

class D {
    public static int $y;
    public static function f() {
        return self::$y;
    }
}
try {
    echo D::f();
    echo "return_read_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
