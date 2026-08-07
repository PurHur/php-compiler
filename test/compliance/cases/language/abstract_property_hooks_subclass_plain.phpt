--TEST--
Language: plain typed property satisfies parent abstract hooked property (#28373, Zend/zend_inheritance.c)
--FILE--
<?php
abstract class A {
    abstract public string $x { get; set; }
}
class Plain extends A {
    public string $x;
}
$p = new Plain();
$p->x = 'hi';
echo $p->x, "\n";
--EXPECT--
hi
