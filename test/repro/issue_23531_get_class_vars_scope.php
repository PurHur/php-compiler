<?php
/**
 * Repro #23531 — get_class_vars() from instance method includes scope-visible defaults.
 *
 * Zend/zend_builtin_functions.c — add_class_vars / zend_get_executed_scope()
 * Run: php bin/vm.php test/repro/issue_23531_get_class_vars_scope.php
 */
class A {
    public $a = 1;
    protected $b = 2;
    private $c = 3;
    public static $sa = 10;
    protected static $sb = 20;
    private static $sc = 30;
    public function vars() { return get_class_vars(__CLASS__); }
}
class B extends A {
    public function vars() { return get_class_vars('A'); }
}
function keys($a) {
    $k = array_keys($a);
    sort($k);
    return implode(',', $k);
}
echo 'out=', keys(get_class_vars('A')), "\n";
echo 'in=', keys((new A)->vars()), "\n";
echo 'child=', keys((new B)->vars()), "\n";
