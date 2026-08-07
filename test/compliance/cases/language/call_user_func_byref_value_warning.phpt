--TEST--
Language: call_user_func* / forward_static_call_array by-ref value Warning (#28793)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

function f(&$a) {
    $a = 2;
}

$x = 1;
call_user_func('f', $x);
echo "cuf=$x\n";

$x = 1;
call_user_func_array('f', [$x]);
echo "cufa=$x\n";

$y = 1;
call_user_func_array('f', [&$y]);
echo "cufa_ref=$y\n";

class C {
    public function m(&$a) {
        $a = 3;
    }
    public static function s(&$a) {
        $a = 4;
    }
}

$c = new C;
$x = 1;
call_user_func([$c, 'm'], $x);
echo "m=$x\n";

$x = 1;
call_user_func_array([$c, 'm'], [&$x]);
echo "m_ref=$x\n";

$x = 1;
call_user_func(['C', 's'], $x);
echo "s=$x\n";

$g = function (&$a) {
    $a = 5;
};
$x = 1;
call_user_func($g, $x);
echo "cl=$x\n";

$x = 1;
call_user_func_array($g, [&$x]);
echo "cl_ref=$x\n";

class A {
    public static function run(): void {
        $x = 1;
        forward_static_call_array('f', [$x]);
        echo "fsc=$x\n";
        $y = 1;
        forward_static_call_array('f', [&$y]);
        echo "fsc_ref=$y\n";
    }
}
A::run();
?>
--EXPECTF--
Warning: f(): Argument #1 ($a) must be passed by reference, value given in %s on line %d
cuf=1

Warning: f(): Argument #1 ($a) must be passed by reference, value given in %s on line %d
cufa=1
cufa_ref=2

Warning: C::m(): Argument #1 ($a) must be passed by reference, value given in %s on line %d
m=1
m_ref=3

Warning: C::s(): Argument #1 ($a) must be passed by reference, value given in %s on line %d
s=1

Warning: {closure}(): Argument #1 ($a) must be passed by reference, value given in %s on line %d
cl=1
cl_ref=5

Warning: f(): Argument #1 ($a) must be passed by reference, value given in %s on line %d
fsc=1
fsc_ref=2
