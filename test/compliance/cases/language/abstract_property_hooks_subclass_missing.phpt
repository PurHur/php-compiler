--TEST--
Language: concrete subclass omitting abstract hooked properties is compile fatal (#28373, re-#10017, Zend/zend_inheritance.c)
--FILE--
<?php
abstract class A {
    abstract public string $x { get; set; }
}
echo "defined A\n";
class Bad extends A {}
echo "Bad class exists\n";
$b = new Bad();
echo "Bad new ok\n";
--EXPECTF--
PHP Fatal error:  Class Bad contains 2 abstract methods and must therefore be declared abstract or implement the remaining methods (A::$x::get, A::$x::set) in %s on line %d
--EXPECT_EXIT--
255
