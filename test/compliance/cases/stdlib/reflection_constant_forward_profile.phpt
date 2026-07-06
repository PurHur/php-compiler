--TEST--
ReflectionConstant class exists on forward profile (#12385, #16837, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(class_exists('ReflectionConstant', false));
echo "\n";
class C12385 { public const FOO = 1; }
$ref = new ReflectionConstant(C12385::class, 'FOO');
var_export($ref->getName());
echo "\n";
var_export($ref->getValue());
echo "\n";
--EXPECT--
true
'FOO'
1
