--TEST--
Stdlib: get_class_vars() scope-visible defaults — JIT (#23531, Zend/zend_builtin_functions.c)
--FILE--
<?php
class A23531j {
    public $a = 1;
    protected $b = 2;
    private $c = 3;
    public static $sa = 10;
    protected static $sb = 20;
    private static $sc = 30;
    public function vars() { return get_class_vars(__CLASS__); }
}
class B23531j extends A23531j {
    public function vars() { return get_class_vars('A23531j'); }
}
function keys23531j($a) {
    $k = array_keys($a);
    sort($k);
    return implode(',', $k);
}
echo 'out=', keys23531j(get_class_vars('A23531j')), "\n";
echo 'in=', keys23531j((new A23531j)->vars()), "\n";
echo 'child=', keys23531j((new B23531j)->vars()), "\n";
--EXPECT--
out=a,sa
in=a,b,c,sa,sb,sc
child=a,b,sa,sb
