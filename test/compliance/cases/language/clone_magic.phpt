--TEST--
language: __clone magic method runs after shallow clone (issue #3170)
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
echo $b->x, "\n";
echo $a->x, "\n";
--EXPECT--
2
1
