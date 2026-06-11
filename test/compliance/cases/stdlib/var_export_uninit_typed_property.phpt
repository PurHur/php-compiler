--TEST--
stdlib var_export() — uninitialized typed property throws Error (#4921, ext/standard/var.c)
--FILE--
<?php
class C {
    public int $x;
}
$c = new C;
unset($c->x);
try {
    var_export($c->x);
    echo "no throw\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Typed property C::$x must not be accessed before initialization
