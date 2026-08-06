--TEST--
ReflectionConstant lacks getDeclaringClass; ReflectionClassConstant keeps it (#28156, re-#17343)
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

$rcc = new ReflectionClassConstant(C17343::class, 'BAR');
var_export(method_exists($rcc, 'getDeclaringClass'));
echo "\n";
var_export($rcc->getDeclaringClass()->getName());
echo "\n";
--EXPECT--
false
true
'C17343'
