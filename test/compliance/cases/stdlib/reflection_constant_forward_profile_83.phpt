--TEST--
ReflectionConstant class exists on PROFILE=8.3 (#25504, re-#12385, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
var_export(class_exists('ReflectionConstant', false));
echo "\n";
define('FOO_RC_25504', 99);
$ref = new ReflectionConstant('FOO_RC_25504');
echo $ref->getName(), '=', $ref->getValue(), "\n";
--EXPECT--
true
FOO_RC_25504=99
