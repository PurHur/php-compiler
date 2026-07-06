--TEST--
Language: ReflectionFunction::isDeprecated() — PHP 8.4 #[\Deprecated] function (#9760, #16821, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
#[\Deprecated(message: 'old fn', since: '8.4')]
function dep(): void {}

function control(): void {}

$rf = new ReflectionFunction('dep');
var_export($rf->isDeprecated());
echo "\n";
$rc = new ReflectionFunction('control');
var_export($rc->isDeprecated());
echo "\n";
--EXPECT--
false
false
