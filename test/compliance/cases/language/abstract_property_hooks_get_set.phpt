--TEST--
Abstract class abstract get/set property hooks — subclass provides both (#6763, Zend/zend_compile.c)
--FILE--
<?php
abstract class A {
    public string $p {
        get;
        set;
    }
}
class B extends A {
    public string $p {
        get => $this->p;
        set => $this->p = $value;
    }
}
$b = new B();
$b->p = 'hi';
echo $b->p, "\n";
--EXPECT--
hi
