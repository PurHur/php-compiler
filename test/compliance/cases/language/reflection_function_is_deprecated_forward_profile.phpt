--TEST--
Language: ReflectionFunction::isDeprecated() — PHP 8.4 #[\Deprecated] function (#9760, ext/reflection/php_reflection.c)
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
true
false
