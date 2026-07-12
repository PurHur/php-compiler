--TEST--
ReflectionConstant::getDeclaringClass() on forward profile (#17343, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C17343 {
    public const FOO = 1;
    protected const BAR = 2;
}

$rc = new ReflectionConstant(C17343::class, 'FOO');
var_export(method_exists($rc, 'getDeclaringClass'));
echo "\n";
var_export($rc->getDeclaringClass()->getName());
echo "\n";

$rcc = new ReflectionClassConstant(C17343::class, 'BAR');
var_export($rcc->getDeclaringClass()->getName());
echo "\n";
--EXPECT--
true
'C17343'
'C17343'
