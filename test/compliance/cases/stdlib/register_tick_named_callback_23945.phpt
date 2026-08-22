--TEST--
stdlib register/unregister_tick_function Zend stub named callback (#23945, ext/standard/basic_functions.stub.php)
--FILE--
<?php
foreach (['register_tick_function', 'unregister_tick_function'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $bits = [];
    foreach ($rf->getParameters() as $p) {
        $bits[] = $p->getName().($p->isVariadic() ? '...' : '');
    }
    echo $fn, ' params=', implode(',', $bits);
    echo ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none';
    $p0 = $rf->getParameters()[0];
    echo ' p0type=', $p0->hasType() ? (string) $p0->getType() : 'none';
    echo ' num=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";
}

$cb = static function (): void {};
register_tick_function(callback: $cb);
unregister_tick_function(callback: $cb);
echo "named ok\n";
?>
--EXPECT--
register_tick_function params=callback,args... ret=bool p0type=callable num=2 req=1
unregister_tick_function params=callback ret=void p0type=callable num=1 req=1
named ok
--EXPECT_EXIT--
0
