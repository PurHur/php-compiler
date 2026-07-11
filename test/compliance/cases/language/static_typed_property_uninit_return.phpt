--TEST--
Language: uninitialized static typed property return via self:: throws Error (#12056)
--FILE--
<?php
class C {
    public static int $x;
    public static function f() {
        return self::$x;
    }
}
try {
    echo C::f();
    echo "read_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Typed static property C::$x must not be accessed before initialization
