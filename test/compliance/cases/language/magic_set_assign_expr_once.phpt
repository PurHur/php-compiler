--TEST--
language: __set once for used property-assign expression (issue #29194)
--FILE--
<?php
class A {
    public int $calls = 0;
    public function __set($k, $v) {
        $this->calls++;
        echo "set\n";
    }
}
$a = new A();
$r = ($a->x = 5);
echo 'calls=', $a->calls, ' r=', $r, "\n";

class B {
    public int $calls = 0;
    public int $gets = 0;
    public function __get($k) {
        $this->gets++;
        echo "get\n";
        return 10;
    }
    public function __set($k, $v) {
        $this->calls++;
        echo "set $v\n";
    }
}
$b = new B();
$b->x += 2;
echo 'calls=', $b->calls, ' gets=', $b->gets, "\n";
--EXPECT--
set
calls=1 r=5
get
set 12
calls=1 gets=1
