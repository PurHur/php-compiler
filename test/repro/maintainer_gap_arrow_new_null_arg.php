<?php
/**
 * Maintainer gap: arrow `fn() => new Class($captured, null)` drops captures —
 * Zend keeps captured values; VM passes null for mixed null+capture ctor args
 * (Zend/zend_compile.c / arrow auto-capture + new lowering).
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

class Pair {
    public $a;
    public $b;
    public function __construct($a, $b) {
        $this->a = $a;
        $this->b = $b;
    }
}

$x = 'captured';

echo "== arrow new Pair(\$x, null) ==\n";
$f = fn() => new Pair($x, null);
$o = $f();
echo 'a='; var_export($o->a); echo ' b='; var_export($o->b); echo "\n";

echo "== arrow new Pair(null, \$x) ==\n";
$f2 = fn() => new Pair(null, $x);
$o2 = $f2();
echo 'a='; var_export($o2->a); echo ' b='; var_export($o2->b); echo "\n";

echo "== arrow new Pair(\$x, 0) control ==\n";
$f3 = fn() => new Pair($x, 0);
$o3 = $f3();
echo 'a='; var_export($o3->a); echo ' b='; var_export($o3->b); echo "\n";

echo "== long closure new Pair(\$x, null) control ==\n";
$f4 = function () use ($x) { return new Pair($x, null); };
$o4 = $f4();
echo 'a='; var_export($o4->a); echo ' b='; var_export($o4->b); echo "\n";
