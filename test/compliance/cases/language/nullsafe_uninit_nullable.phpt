--TEST--
Language: nullsafe ?-> on uninitialized nullable property short-circuits to null (#5220)
--FILE--
<?php
class B {
    public string $v = 'ok';
}
class A {
    public ?B $b;
}
$a = new A();
echo $a->b?->v ?? 'null', "\n";
try {
    echo $a->b, "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
null
Typed property A::$b must not be accessed before initialization
