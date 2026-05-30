--TEST--
language: parent::__clone() chaining after shallow clone (issue #3170)
--FILE--
<?php
class Base {
    public int $x = 1;
    public function __clone() {
        $this->x = 2;
    }
}
class Child extends Base {
    public function __clone() {
        parent::__clone();
        $this->x = 3;
    }
}
$a = new Child();
$b = clone $a;
echo $b->x;
--EXPECT--
3
