--TEST--
Stdlib: get_class_vars() includes scope-visible private/protected defaults (#23531, Zend/zend_builtin_functions.c)
--FILE--
<?php
class A23531 {
    public $a = 1;
    protected $b = 2;
    private $c = 3;
    public static $sa = 10;
    protected static $sb = 20;
    private static $sc = 30;
    public function vars() { return get_class_vars(__CLASS__); }
}
class B23531 extends A23531 {
    public function vars() { return get_class_vars('A23531'); }
}
function keys23531($a) {
    $k = array_keys($a);
    sort($k);
    return implode(',', $k);
}
echo 'out=', keys23531(get_class_vars('A23531')), "\n";
echo 'in=', keys23531((new A23531)->vars()), "\n";
echo 'child=', keys23531((new B23531)->vars()), "\n";
--EXPECT--
out=a,sa
in=a,b,c,sa,sb,sc
child=a,b,sa,sb
