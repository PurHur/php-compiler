--TEST--
Language: is_null() on uninitialized typed property throws Error (#10874)
--FILE--
<?php
class C {
    public int $x;
    public ?int $y;
}
$c = new C;
try {
    is_null($c->x);
    echo "no-error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    is_null($c->y);
    echo "no-error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$c->y = null;
echo is_null($c->y) ? "y\n" : "n\n";
--EXPECT--
Typed property C::$x must not be accessed before initialization
Typed property C::$y must not be accessed before initialization
y
