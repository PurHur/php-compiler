--TEST--
ReflectionConstant::getAttributes() phantom on PROFILE=8.4 (#28157, ext/reflection/php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$c = new ReflectionConstant('PHP_VERSION');
echo 'getAttributes=', method_exists($c, 'getAttributes') ? '1' : '0', "\n";
class C28157p { public const X = 1; }
$rcc = new ReflectionClassConstant(C28157p::class, 'X');
echo 'rcc_getAttributes=', method_exists($rcc, 'getAttributes') ? '1' : '0', "\n";
--EXPECT--
getAttributes=0
rcc_getAttributes=1
