--TEST--
language: __clone magic method (JIT, issue #3170)
--JIT--
--FILE--
<?php
class C {
    public int $x = 1;
    public function __clone() {
        $this->x = 2;
    }
}
$a = new C();
$b = clone $a;
echo $b->x;
--EXPECT--
2
