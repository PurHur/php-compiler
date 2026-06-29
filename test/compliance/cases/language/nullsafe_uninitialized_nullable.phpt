--TEST--
Language: nullsafe ?-> chain on uninitialized nullable property with ?? (#13747)
--FILE--
<?php
class B {
    public string $x = 'ok';
}
class A {
    public ?B $b;
}
$a = new A();
echo $a?->b?->x ?? 'n', "\n";
class X {
    public ?Y $y;
}
class Y {
    public int $v = 1;
}
$x = new X();
try {
    var_export($x?->y?->v);
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
n
Error: Typed property X::$y must not be accessed before initialization
