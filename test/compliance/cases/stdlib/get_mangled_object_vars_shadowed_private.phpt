--TEST--
Stdlib: get_mangled_object_vars + (array) keep parent+child private same-name slots (#22521)
--FILE--
<?php
class A22521 {
    private $x = 1;
    protected $y = 2;
    public $z = 3;
    public function getAx() {
        return $this->x;
    }
}
class B22521 extends A22521 {
    private $x = 4;
    public function getBx() {
        return $this->x;
    }
}
$b = new B22521();
$m = get_mangled_object_vars($b);
ksort($m);
foreach ($m as $k => $v) {
    echo bin2hex($k), '=>', var_export($v, true), "\n";
}
$a = (array) $b;
ksort($a);
foreach ($a as $k => $v) {
    echo 'cast:', bin2hex($k), '=>', var_export($v, true), "\n";
}
echo 'ax=', var_export($b->getAx(), true), "\n";
echo 'bx=', var_export($b->getBx(), true), "\n";
?>
--EXPECT--
002a0079=>2
004132323532310078=>1
004232323532310078=>4
7a=>3
cast:002a0079=>2
cast:004132323532310078=>1
cast:004232323532310078=>4
cast:7a=>3
ax=1
bx=4
