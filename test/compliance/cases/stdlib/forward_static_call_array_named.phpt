--TEST--
stdlib forward_static_call_array Zend stub named callback/args (#26237, ext/standard/basic_functions.stub.php)
--FILE--
<?php
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
--EXPECT--
params=callback,args
num=2 req=2
42
--EXPECT_EXIT--
0
