--TEST--
Abstract class abstract property hooks — subclass provides get hook (#6634, Zend/zend_compile.c)
--FILE--
<?php
abstract class A {
    abstract public string $label { get; }
}
final class C extends A {
    public string $label { get => 'child'; }
}
echo (new C())->label, "\n";
--EXPECT--
child
