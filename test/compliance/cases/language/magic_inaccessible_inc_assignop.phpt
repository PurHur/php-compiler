--TEST--
Language: ++/-- and compound assign on inaccessible props use __get/__set (#25687, zend_object_handlers.c)
--FILE--
<?php
class A {
    private $x = 1;
    public function __get($n) {
        echo "GET\n";
        return 10;
    }
    public function __set($n, $v) {
        echo "SET:$v\n";
    }
    public function bumpInScope() {
        $this->x++;
        echo "in=$this->x\n";
    }
}

$a = new A();
$a->x++;
$a->x += 5;
--$a->x;
$a->x -= 3;
$a->bumpInScope();

class B {
    protected $y = 1;
    public function __get($n) {
        echo "GET_Y\n";
        return 20;
    }
    public function __set($n, $v) {
        echo "SET_Y:$v\n";
    }
}
$b = new B();
$b->y++;
--EXPECT--
GET
SET:11
GET
SET:15
GET
SET:9
GET
SET:7
in=2
GET_Y
SET_Y:21
