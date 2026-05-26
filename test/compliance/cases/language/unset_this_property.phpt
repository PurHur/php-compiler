--TEST--
unset() on $this->property inside a method
--FILE--
<?php
class C {
    public $p = 1;
    public function clear(): void {
        unset($this->p);
    }
}
$o = new C();
$o->clear();
echo isset($o->p) ? "y" : "n", "\n";
--EXPECT--
n
