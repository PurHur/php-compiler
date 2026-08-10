--TEST--
Trait abstract property hooks — subclass of abstract using class (#7316/#30009, Zend/zend_inheritance.c)
--FILE--
<?php
trait T {
    abstract public string $x { get; set; }
}
abstract class C {
    use T;
}
class D extends C {
    public string $x {
        get => $this->__x;
        set(string $v) { $this->__x = $v; }
    }
    private string $__x = '';
}
$c = new D();
$c->x = 'hi';
echo $c->x, "\n";
--EXPECT--
hi
