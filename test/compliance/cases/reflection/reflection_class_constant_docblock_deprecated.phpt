--TEST--
Reflection: ReflectionClassConstant::isDeprecated() on @deprecated docblock (#17647, ext/reflection/php_reflection.c)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== '8.5') {
    die('skip docblock deprecated class constants require PHP_COMPILER_PROFILE=8.4');
}
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    /** @deprecated */
    public const X = 1;
    public const Y = 2;
}

$deprecated = new ReflectionClassConstant(C::class, 'X');
$control = new ReflectionClassConstant(C::class, 'Y');
var_export($deprecated->isDeprecated());
echo "\n";
var_export($control->isDeprecated());
echo "\n";
--EXPECT--
true
false
