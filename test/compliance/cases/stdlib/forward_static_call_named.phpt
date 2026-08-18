--TEST--
stdlib forward_static_call Zend stub named callback/args (#24040, ext/standard/basic_functions.stub.php)
--FILE--
<?php
class A
{
    public static function f($x = 21)
    {
        return $x * 2;
    }
}

$rf = new ReflectionFunction('forward_static_call');
$bits = [];
foreach ($rf->getParameters() as $p) {
    $bits[] = $p->getName().($p->isVariadic() ? '...' : '');
}
echo 'params=', implode(',', $bits), "\n";
echo 'isV=', $rf->isVariadic() ? '1' : '0', "\n";
echo 'num=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";

class B extends A
{
    public static function run(): void
    {
        echo 'named=', forward_static_call(callback: [A::class, 'f']), "\n";
        echo 'pos=', forward_static_call([A::class, 'f'], 3), "\n";
    }
}
B::run();
?>
--EXPECT--
params=callback,args...
isV=1
num=2 req=1
named=42
pos=6
--EXPECT_EXIT--
0
