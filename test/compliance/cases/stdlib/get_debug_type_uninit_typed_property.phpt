--TEST--
stdlib get_debug_type() — uninitialized typed property throws Error (#4919, ext/standard/type.c)
--FILE--
<?php
class C {
    public int $x;
}
$c = new C;
unset($c->x);
try {
    get_debug_type($c->x);
    echo "no throw\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Typed property C::$x must not be accessed before initialization
