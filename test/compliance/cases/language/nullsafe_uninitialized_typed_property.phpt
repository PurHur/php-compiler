--TEST--
Language: nullsafe ?-> on uninitialized typed nullable property throws Error (#5361)
--FILE--
<?php
class X {
    public ?Y $y;
}
class Y {
    public int $v = 1;
}
class A {
    public ?B $b = null;
}
class B {
    public int $n = 0;
}
$x = new X();
try {
    var_export($x?->y?->v);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$a = new A();
var_export($a?->b?->n);
echo "\n";
--EXPECT--
Error: Typed property X::$y must not be accessed before initialization
NULL
