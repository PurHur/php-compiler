--TEST--
JIT: gettype() — uninitialized typed property throws Error (#4894)
--FILE--
<?php
class C {
    public int $x;
}
$c = new C;
unset($c->x);
try {
    gettype($c->x);
    echo "no throw\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Typed property C::$x must not be accessed before initialization
