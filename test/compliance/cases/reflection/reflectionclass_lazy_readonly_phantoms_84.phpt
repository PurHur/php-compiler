--TEST--
ReflectionClass lazy/readonly phantoms absent on PROFILE=8.4 (#28516, ext/reflection/php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$phantoms = [
    'createLazyGhost',
    'createLazyProxy',
    'getLazyPropertyNames',
    'getReadOnlyProperties',
    'resetAsLazyObject',
    'getLazyInitializationException',
    'getLazyProxyFactory',
];
$real = [
    'newLazyGhost',
    'newLazyProxy',
    'getLazyInitializer',
];
foreach ($phantoms as $m) {
    echo $m, '=', method_exists(ReflectionClass::class, $m) ? '1' : '0', "\n";
}
foreach ($real as $m) {
    echo 'real_', $m, '=', method_exists(ReflectionClass::class, $m) ? '1' : '0', "\n";
}
--EXPECT--
createLazyGhost=0
createLazyProxy=0
getLazyPropertyNames=0
getReadOnlyProperties=0
resetAsLazyObject=0
getLazyInitializationException=0
getLazyProxyFactory=0
real_newLazyGhost=1
real_newLazyProxy=1
real_getLazyInitializer=1
