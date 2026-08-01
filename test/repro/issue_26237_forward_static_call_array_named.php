<?php
/**
 * Issue #26237 — forward_static_call_array Reflection names + named callback:/args:.
 * php-src: ext/standard/basic_functions.stub.php
 */
class A
{
    public static function f($x)
    {
        return $x * 2;
    }
}

$rf = new ReflectionFunction('forward_static_call_array');
$bits = [];
foreach ($rf->getParameters() as $p) {
    $bits[] = $p->getName();
}
echo 'params=', implode(',', $bits), "\n";
echo 'num=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";
var_export(forward_static_call_array(callback: ['A', 'f'], args: [21]));
echo "\n";
?>
