--TEST--
Language: abstract trait hooks via abstract class + subclass impl (#30009 / #7316, zend_property_hooks.c)
--FILE--
<?php
trait T {
    abstract public int $x { get; }
}
abstract class C {
    use T;
}
class D extends C {
    public int $x {
        get => 5;
    }
}
echo (new D)->x, "\n";
--EXPECT--
5
