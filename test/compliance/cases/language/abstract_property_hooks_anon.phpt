--TEST--
Abstract property hook missing on anonymous subclass — runtime fatal (#6983, zend_property_hooks.c)
--FILE--
<?php
abstract class A {
    abstract public string $x { get; }
}
new class extends A {};
echo "instantiated\n";
--EXPECT_EXIT--
255
