<?php
// #31720 — arrow fn `new Class($captured, null)` must keep auto-captures (Zend)

error_reporting(E_ALL);
ini_set('display_errors', '1');

class Pair
{
    public $a;
    public $b;

    public function __construct($a, $b)
    {
        $this->a = $a;
        $this->b = $b;
    }
}

$x = 'captured';

$mixedNull = (fn() => new Pair($x, null))();
echo 'mixed_null a=';
var_export($mixedNull->a);
echo ' b=';
var_export($mixedNull->b);
echo "\n";

$nullThenCap = (fn() => new Pair(null, $x))();
echo 'null_then_cap a=';
var_export($nullThenCap->a);
echo ' b=';
var_export($nullThenCap->b);
echo "\n";

$intControl = (fn() => new Pair($x, 0))();
echo 'int_control a=';
var_export($intControl->a);
echo ' b=';
var_export($intControl->b);
echo "\n";

$dupControl = (fn() => new Pair($x, $x))();
echo 'dup_control a=';
var_export($dupControl->a);
echo ' b=';
var_export($dupControl->b);
echo "\n";

$long = (function () use ($x) {
    return new Pair($x, null);
})();
echo 'long_use a=';
var_export($long->a);
echo ' b=';
var_export($long->b);
echo "\n";
