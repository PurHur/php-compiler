--TEST--
parent::class returns parent class name (issue #3093, Zend zend_compile.c ClassConstFetch)
--FILE--
<?php
class Parent_ {}
class Child extends Parent_ {
    public function f(): void {
        echo parent::class;
        echo "\n";
    }
}
(new Child())->f();
--EXPECT--
Parent_
