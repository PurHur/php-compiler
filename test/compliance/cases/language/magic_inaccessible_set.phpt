--TEST--
Language: assign to inaccessible private/protected props uses __set (#25686, zend_object_handlers.c)
--FILE--
<?php
class A {
    private $x = 1;
    public function __set($n, $v) {
        echo "set:$n=$v\n";
    }
    public function write() {
        $this->x = 7;
        echo "slot=$this->x\n";
    }
}
$a = new A();
$a->x = 5;
$a->write();

class B {
    protected $y = 1;
    public function __set($n, $v) {
        echo "set:$n=$v\n";
    }
}
$b = new B();
$b->y = 9;

class C {
    private $z = 1;
}
$c = new C();
try {
    $c->z = 3;
    echo "NOERR\n";
} catch (Error $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}
--EXPECT--
set:x=5
slot=7
set:y=9
ERR: Cannot access private property C::$z
