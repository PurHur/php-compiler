--TEST--
stdlib settype() — uninitialized non-nullable typed property by-ref throws Error (#13884, ext/standard/type.c)
--FILE--
<?php
class C {
    public int $x;
}
$c = new C;
try {
    settype($c->x, 'int');
    echo "no throw\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot access uninitialized non-nullable property C::$x by reference
