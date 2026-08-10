--TEST--
Trait abstract property hooks — using class must implement get/set (#7316, Zend/zend_compile.c)
--FILE--
<?php
trait T {
    abstract public string $x { get; set; }
}
class C {
    use T;
}
$c = new C();
$c->x = 'a';
echo $c->x, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
PHP Fatal error:  Class C contains 2 abstract methods and must therefore be declared abstract or implement the remaining methods (T::$x::get, T::$x::set) in %s on line %d
