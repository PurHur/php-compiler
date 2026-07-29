--TEST--
ReflectionFunction call_user_func/array params match Zend stubs (#24449)
--FILE--
<?php
echo 'fe=', function_exists('call_user_func') ? '1' : '0', "\n";
echo 'call=', call_user_func('strlen', 'ab'), "\n";

$rf = new ReflectionFunction('call_user_func');
$bits = [];
foreach ($rf->getParameters() as $p) {
    $bits[] = $p->getName().($p->isVariadic() ? '...' : '');
}
echo 'params=', implode(',', $bits), "\n";
echo 'isV=', $rf->isVariadic() ? '1' : '0', "\n";
echo 'num=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";

$rf2 = new ReflectionFunction('call_user_func_array');
$bits2 = [];
foreach ($rf2->getParameters() as $p) {
    $bits2[] = $p->getName().($p->isVariadic() ? '...' : '');
}
echo 'cufa=', implode(',', $bits2), "\n";
?>
--EXPECT--
fe=1
call=2
params=callback,args...
isV=1
num=2 req=1
cufa=callback,args
