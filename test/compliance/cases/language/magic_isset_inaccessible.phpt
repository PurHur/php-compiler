--TEST--
isset/empty on inaccessible private/protected declared props use __isset (zend_std_has_property, #25668)
--FILE--
<?php
class A {
    private $x = 1;
    protected $y = 2;
    public function __isset($n) {
        echo "ISSET_$n\n";
        return 'x' === $n;
    }
    public function inScope() {
        var_export(isset($this->x));
        echo "\n";
    }
}
class B extends A {
    public function subY() {
        var_export(isset($this->y));
        echo "\n";
    }
    public function subX() {
        var_export(isset($this->x));
        echo "\n";
    }
}
$a = new A();
var_export(isset($a->x));
echo "\n";
var_export(empty($a->x));
echo "\n";
var_export(isset($a->y));
echo "\n";
$a->inScope();
$b = new B();
$b->subY();
$b->subX();

class C {
    private $x = 1;
}
$c = new C();
var_export(isset($c->x));
echo "\n";
--EXPECT--
ISSET_x
true
ISSET_x
true
ISSET_y
false
true
true
ISSET_x
true
false
