--TEST--
unset on inaccessible private/protected declared props use __unset or Error (zend_std_unset_property, #25668)
--FILE--
<?php
class A {
    private $x = 1;
    public function __unset($n) {
        echo "UNSET_$n\n";
    }
}
$a = new A();
unset($a->x);
$r = new ReflectionProperty(A::class, 'x');
$r->setAccessible(true);
var_export($r->getValue($a));
echo "\n";

class B {
    private $x = 1;
    protected $y = 2;
}
$b1 = new B();
try {
    unset($b1->x);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$b2 = new B();
try {
    unset($b2->y);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

class P {
    private $x = 1;
}
class C extends P {
    public function go() {
        unset($this->x);
        echo "child-noop\n";
    }
}
$c = new C();
$c->go();
$rp = new ReflectionProperty(P::class, 'x');
$rp->setAccessible(true);
var_export($rp->getValue($c));
echo "\n";

class P2 {
    private $x = 1;
    public function __unset($n) {
        echo "UNSET_$n\n";
    }
}
class C2 extends P2 {
    public function go() {
        unset($this->x);
    }
}
(new C2())->go();
--EXPECT--
UNSET_x
1
Cannot access private property B::$x
Cannot access protected property B::$y
child-noop
1
UNSET_x
