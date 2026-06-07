--TEST--
Trait abstract property hooks — using class provides concrete hooks (#7316, Zend/zend_compile.c)
--FILE--
<?php
trait T {
    public string $x { get; set; }
}
class C {
    use T;
    public string $x {
        get => $this->__x;
        set(string $v) { $this->__x = $v; }
    }
    private string $__x = '';
}
$c = new C();
$c->x = 'hi';
echo $c->x, "\n";
--EXPECT--
hi
